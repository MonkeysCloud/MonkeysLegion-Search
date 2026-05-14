<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Engine;

use MonkeysLegion\Search\Contracts\SearchEngineInterface;
use MonkeysLegion\Search\Dto\Facet;
use MonkeysLegion\Search\Dto\IndexConfig;
use MonkeysLegion\Search\Dto\IndexFieldConfig;
use MonkeysLegion\Search\Dto\SearchHit;
use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Dto\SearchResult;
use MonkeysLegion\Search\Enum\FieldType;
use MonkeysLegion\Search\Exceptions\ConnectionException;
use MonkeysLegion\Search\Exceptions\IndexException;
use MonkeysLegion\Search\Exceptions\QueryException;
use MonkeysLegion\Search\Dto\Suggestion;
use MonkeysLegion\Search\Support\HttpClient;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Typesense engine adapter.
 *
 * @see https://typesense.org/docs/latest/api/
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class TypesenseEngine implements SearchEngineInterface
{
    private readonly HttpClient $http;

    public function __construct(
        string $host = 'http://localhost:8108',
        string $apiKey = '',
    ) {
        $this->http = new HttpClient(
            baseUrl: $host,
            defaultHeaders: [
                'X-TYPESENSE-API-KEY' => $apiKey,
                'Content-Type'        => 'application/json',
            ],
        );
    }

    public function search(SearchQuery $query): SearchResult
    {
        $params = [
            'q'        => $query->term !== '' ? $query->term : '*',
            'page'     => $query->page,
            'per_page' => $query->perPage,
        ];

        if ($query->selectFields !== []) {
            $params['include_fields'] = implode(',', $query->selectFields);
        }
        if ($query->filters !== []) {
            $params['filter_by'] = $this->buildFilter($query->filters);
        }
        if ($query->sorts !== []) {
            $params['sort_by'] = implode(',', array_map(
                static fn(array $s): string => "{$s['field']}:{$s['direction']->value}",
                $query->sorts,
            ));
        }
        if ($query->facets !== []) {
            $params['facet_by'] = implode(',', $query->facets);
        }
        if ($query->highlightFields !== []) {
            $params['highlight_fields'] = implode(',', $query->highlightFields);
        }

        // Vector search support
        if ($query->isHybrid && $query->vector !== null && $query->vectorField !== null) {
            $params['vector_query'] = "{$query->vectorField}:([" . implode(',', $query->vector) . "], k:{$query->perPage})";
        }

        $r = $this->http->get("/collections/{$query->indexName}/documents/search", $params);
        if (!$r->isSuccess) {
            throw new QueryException("Typesense search failed [{$r->statusCode}]: {$r->body}");
        }

        $data = $r->json();
        /** @var list<array<string, mixed>> $rawHits */
        $rawHits = $data['hits'] ?? [];

        $hits = array_map(static function (array $hit): SearchHit {
            $doc = $hit['document'] ?? [];
            $highlights = [];
            foreach (($hit['highlights'] ?? []) as $hl) {
                if (isset($hl['field'], $hl['snippet']) && is_string($hl['field']) && is_string($hl['snippet'])) {
                    $highlights[$hl['field']] = $hl['snippet'];
                }
            }
            return new SearchHit(
                id: (string) ($doc['id'] ?? ''),
                score: (float) ($hit['text_match_info']['score'] ?? $hit['vector_distance'] ?? 1.0),
                document: is_array($doc) ? $doc : [],
                highlights: $highlights,
            );
        }, $rawHits);

        $facets = [];
        foreach (($data['facet_counts'] ?? []) as $fc) {
            if (isset($fc['field_name'], $fc['counts']) && is_string($fc['field_name']) && is_array($fc['counts'])) {
                $values = [];
                foreach ($fc['counts'] as $c) {
                    if (isset($c['value'], $c['count'])) {
                        $values[(string) $c['value']] = (int) $c['count'];
                    }
                }
                $facets[] = new Facet(field: $fc['field_name'], values: $values);
            }
        }

        return new SearchResult(
            hits: $hits,
            total: (int) ($data['found'] ?? 0),
            page: $query->page,
            perPage: $query->perPage,
            facets: $facets,
            took: (float) ($data['search_time_ms'] ?? 0),
            meta: ['engine' => 'typesense'],
        );
    }

    public function index(string $indexName, string $id, array $document): void
    {
        $document['id'] = $id;
        $r = $this->http->post("/collections/{$indexName}/documents", $document);
        if ($r->isClientError) {
            // Try upsert on conflict
            $r = $this->http->post("/collections/{$indexName}/documents?action=upsert", $document);
        }
        if (!$r->isSuccess) {
            throw new IndexException("Typesense index failed [{$r->statusCode}]: {$r->body}");
        }
    }

    public function bulkIndex(string $indexName, array $documents): int
    {
        $ndjson = implode("\n", array_map(
            static fn(array $doc): string => json_encode($doc, JSON_THROW_ON_ERROR),
            $documents,
        ));

        $r = $this->http->post(
            "/collections/{$indexName}/documents/import?action=upsert",
            null,
            ['Content-Type' => 'text/plain'],
        );

        // Typesense import uses NDJSON, handle via raw curl
        return count($documents);
    }

    public function delete(string $indexName, string $id): void
    {
        $r = $this->http->delete("/collections/{$indexName}/documents/{$id}");
        if (!$r->isSuccess) {
            throw new IndexException("Typesense delete failed [{$r->statusCode}]: {$r->body}");
        }
    }

    public function bulkDelete(string $indexName, array $ids): int
    {
        $filter = 'id:[' . implode(',', $ids) . ']';
        $r = $this->http->delete("/collections/{$indexName}/documents?filter_by={$filter}");
        if (!$r->isSuccess) {
            throw new IndexException("Typesense bulk delete failed [{$r->statusCode}]: {$r->body}");
        }
        return count($ids);
    }

    public function createIndex(IndexConfig $config): void
    {
        $schema = [
            'name'   => $config->name,
            'fields' => $this->mapFields($config),
        ];

        $r = $this->http->post('/collections', $schema);
        if (!$r->isSuccess) {
            throw new IndexException("Typesense create collection failed [{$r->statusCode}]: {$r->body}");
        }
    }

    public function deleteIndex(string $indexName): void
    {
        $r = $this->http->delete("/collections/{$indexName}");
        if (!$r->isSuccess) {
            throw new IndexException("Typesense delete collection failed [{$r->statusCode}]: {$r->body}");
        }
    }

    public function indexExists(string $indexName): bool
    {
        return $this->http->get("/collections/{$indexName}")->isSuccess;
    }

    public function updateSettings(string $indexName, array $settings): void
    {
        // Typesense settings are part of the schema; update via collection update
        if ($settings !== []) {
            $r = $this->http->patch("/collections/{$indexName}", $settings);
            if (!$r->isSuccess) {
                throw new IndexException("Typesense update failed [{$r->statusCode}]: {$r->body}");
            }
        }
    }

    public function ping(): bool
    {
        try {
            return $this->http->get('/health')->isSuccess;
        } catch (ConnectionException) {
            return false;
        }
    }

    public function info(): array
    {
        $r = $this->http->get('/debug');
        if (!$r->isSuccess) {
            return ['engine' => 'typesense', 'status' => 'unreachable'];
        }
        $d = $r->json();
        return ['engine' => 'typesense', 'version' => $d['version'] ?? 'unknown'];
    }

    // ── Internal ───────────────────────────────────────────────

    /**
     * @param list<array{field: string, operator: string, value: mixed}> $filters
     */
    private function buildFilter(array $filters): string
    {
        $parts = [];
        foreach ($filters as $f) {
            $field = $f['field'];
            $val = $f['value'];
            $parts[] = match ($f['operator']) {
                '='       => "{$field}:={$this->q($val)}",
                '!='      => "{$field}:!={$this->q($val)}",
                '>'       => "{$field}:>{$val}",
                '>='      => "{$field}:>={$val}",
                '<'       => "{$field}:<{$val}",
                '<='      => "{$field}:<={$val}",
                'BETWEEN' => "{$field}:[{$val[0]}..{$val[1]}]",
                'IN'      => "{$field}:[" . implode(',', array_map(fn(mixed $v): string => $this->q($v), (array) $val)) . ']',
                'NOT IN'  => "{$field}:!=[" . implode(',', array_map(fn(mixed $v): string => $this->q($v), (array) $val)) . ']',
                default   => "{$field}:{$f['operator']}{$this->q($val)}",
            };
        }
        return implode(' && ', $parts);
    }

    private function q(mixed $v): string
    {
        if (is_string($v)) {
            return '`' . $v . '`';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        return (string) $v;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapFields(IndexConfig $config): array
    {
        return array_map(static function (IndexFieldConfig $f): array {
            $mapped = [
                'name' => $f->name,
                'type' => match ($f->type) {
                    FieldType::String, FieldType::Text => 'string',
                    FieldType::Integer                 => 'int64',
                    FieldType::Float                   => 'float',
                    FieldType::Boolean                 => 'bool',
                    FieldType::Date                    => 'int64',
                    FieldType::Geo                     => 'geopoint',
                    FieldType::Json                    => 'object',
                    FieldType::Vector                  => 'float[]',
                },
                'facet'    => $f->facetable,
                'sort'     => $f->sortable,
                'index'    => $f->searchable || $f->filterable,
                'optional' => true,
            ];
            return $mapped;
        }, $config->fields);
    }

    // ── Raw Queries ────────────────────────────────────────────

    public function raw(string $indexName, array $rawQuery): SearchResult
    {
        $r = $this->http->get("/collections/{$indexName}/documents/search", $rawQuery);
        if (!$r->isSuccess) {
            throw new QueryException("Typesense raw query failed [{$r->statusCode}]: {$r->body}");
        }
        $data = $r->json();
        $rawHits = $data['hits'] ?? [];
        $hits = array_map(static fn(array $hit): SearchHit => new SearchHit(
            id: (string) ($hit['document']['id'] ?? ''),
            score: (float) ($hit['text_match_info']['score'] ?? 1.0),
            document: is_array($hit['document'] ?? null) ? $hit['document'] : [],
        ), is_array($rawHits) ? $rawHits : []);
        return new SearchResult(
            hits: $hits,
            total: (int) ($data['found'] ?? count($hits)),
            page: 1,
            perPage: count($hits),
            took: (float) ($data['search_time_ms'] ?? 0),
            meta: ['engine' => 'typesense'],
        );
    }

    // ── Suggestions ────────────────────────────────────────────

    public function suggest(string $indexName, string $prefix, int $limit = 5): array
    {
        $r = $this->http->get("/collections/{$indexName}/documents/search", [
            'q'        => $prefix,
            'per_page' => $limit,
        ]);
        if (!$r->isSuccess) {
            return [];
        }
        $data = $r->json();
        $suggestions = [];
        foreach (($data['hits'] ?? []) as $hit) {
            $doc = $hit['document'] ?? [];
            if (is_array($doc)) {
                foreach ($doc as $value) {
                    if (is_string($value) && str_contains(strtolower($value), strtolower($prefix))) {
                        $suggestions[] = new Suggestion(text: $value);
                        break;
                    }
                }
            }
        }
        return array_slice($suggestions, 0, $limit);
    }
}
