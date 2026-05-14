<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Engine;

use MonkeysLegion\Search\Contracts\SearchEngineInterface;
use MonkeysLegion\Search\Dto\Facet;
use MonkeysLegion\Search\Dto\IndexConfig;
use MonkeysLegion\Search\Dto\SearchHit;
use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Dto\SearchResult;
use MonkeysLegion\Search\Exceptions\ConnectionException;
use MonkeysLegion\Search\Exceptions\IndexException;
use MonkeysLegion\Search\Exceptions\QueryException;
use MonkeysLegion\Search\Dto\Suggestion;
use MonkeysLegion\Search\Support\HttpClient;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Meilisearch engine adapter.
 *
 * @see https://www.meilisearch.com/docs/reference/api/overview
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class MeilisearchEngine implements SearchEngineInterface
{
    private readonly HttpClient $http;

    public function __construct(
        string $host = 'http://localhost:7700',
        string $apiKey = '',
    ) {
        $headers = ['Accept' => 'application/json'];
        if ($apiKey !== '') {
            $headers['Authorization'] = "Bearer {$apiKey}";
        }
        $this->http = new HttpClient(baseUrl: $host, defaultHeaders: $headers);
    }

    public function search(SearchQuery $query): SearchResult
    {
        $body = [
            'q'      => $query->term,
            'offset' => $query->offset,
            'limit'  => $query->perPage,
        ];

        if ($query->filters !== []) {
            $body['filter'] = $this->buildFilter($query->filters);
        }
        if ($query->facets !== []) {
            $body['facets'] = $query->facets;
        }
        if ($query->sorts !== []) {
            $body['sort'] = array_map(
                static fn(array $s): string => "{$s['field']}:{$s['direction']->value}",
                $query->sorts,
            );
        }
        if ($query->highlightFields !== []) {
            $body['attributesToHighlight'] = $query->highlightFields;
            $body['highlightPreTag'] = '<em>';
            $body['highlightPostTag'] = '</em>';
        }
        if ($query->selectFields !== []) {
            $body['attributesToRetrieve'] = $query->selectFields;
        }

        $r = $this->http->post("/indexes/{$query->indexName}/search", $body);
        if (!$r->isSuccess) {
            throw new QueryException("Meilisearch search failed [{$r->statusCode}]: {$r->body}");
        }

        $data = $r->json();
        /** @var list<array<string, mixed>> $rawHits */
        $rawHits = $data['hits'] ?? [];

        $hits = array_map(static function (array $hit) use ($query): SearchHit {
            $highlights = [];
            $formatted = $hit['_formatted'] ?? [];
            if (is_array($formatted)) {
                foreach ($query->highlightFields as $f) {
                    if (isset($formatted[$f]) && is_string($formatted[$f])) {
                        $highlights[$f] = $formatted[$f];
                    }
                }
            }
            $score = (float) ($hit['_rankingScore'] ?? 1.0);
            unset($hit['_formatted'], $hit['_rankingScore']);
            return new SearchHit(id: (string) ($hit['id'] ?? ''), score: $score, document: $hit, highlights: $highlights);
        }, $rawHits);

        $facets = [];
        $fd = $data['facetDistribution'] ?? [];
        if (is_array($fd)) {
            foreach ($fd as $field => $values) {
                if (is_string($field) && is_array($values)) {
                    /** @var array<string, int> $values */
                    $facets[] = new Facet(field: $field, values: $values);
                }
            }
        }

        return new SearchResult(
            hits: $hits,
            total: (int) ($data['estimatedTotalHits'] ?? $data['totalHits'] ?? count($hits)),
            page: $query->page,
            perPage: $query->perPage,
            facets: $facets,
            took: (float) ($data['processingTimeMs'] ?? 0),
            meta: ['engine' => 'meilisearch'],
        );
    }

    public function index(string $indexName, string $id, array $document): void
    {
        $document['id'] = $id;
        $this->bulkIndex($indexName, [$document]);
    }

    public function bulkIndex(string $indexName, array $documents): int
    {
        $r = $this->http->post("/indexes/{$indexName}/documents", $documents);
        if (!$r->isSuccess) {
            throw new IndexException("Meilisearch bulk index failed [{$r->statusCode}]: {$r->body}");
        }
        return count($documents);
    }

    public function delete(string $indexName, string $id): void
    {
        $r = $this->http->delete("/indexes/{$indexName}/documents/{$id}");
        if (!$r->isSuccess) {
            throw new IndexException("Meilisearch delete failed [{$r->statusCode}]: {$r->body}");
        }
    }

    public function bulkDelete(string $indexName, array $ids): int
    {
        $r = $this->http->post("/indexes/{$indexName}/documents/delete-batch", $ids);
        if (!$r->isSuccess) {
            throw new IndexException("Meilisearch bulk delete failed [{$r->statusCode}]: {$r->body}");
        }
        return count($ids);
    }

    public function createIndex(IndexConfig $config): void
    {
        $r = $this->http->post('/indexes', ['uid' => $config->name, 'primaryKey' => $config->primaryKey]);
        if (!$r->isSuccess) {
            throw new IndexException("Meilisearch create index failed [{$r->statusCode}]: {$r->body}");
        }
        $this->applyConfigSettings($config);
    }

    public function deleteIndex(string $indexName): void
    {
        $r = $this->http->delete("/indexes/{$indexName}");
        if (!$r->isSuccess) {
            throw new IndexException("Meilisearch delete index failed [{$r->statusCode}]: {$r->body}");
        }
    }

    public function indexExists(string $indexName): bool
    {
        return $this->http->get("/indexes/{$indexName}")->isSuccess;
    }

    public function updateSettings(string $indexName, array $settings): void
    {
        $r = $this->http->patch("/indexes/{$indexName}/settings", $settings);
        if (!$r->isSuccess) {
            throw new IndexException("Meilisearch update settings failed [{$r->statusCode}]: {$r->body}");
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
        $r = $this->http->get('/version');
        if (!$r->isSuccess) {
            return ['engine' => 'meilisearch', 'status' => 'unreachable'];
        }
        $d = $r->json();
        return ['engine' => 'meilisearch', 'version' => $d['pkgVersion'] ?? 'unknown'];
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
                '='       => "{$field} = " . $this->q($val),
                '!='      => "{$field} != " . $this->q($val),
                '>', '>=', '<', '<=' => "{$field} {$f['operator']} {$val}",
                'BETWEEN' => "{$field} {$val[0]} TO {$val[1]}",
                'IN'      => "{$field} IN [" . implode(', ', array_map(fn(mixed $v): string => $this->q($v), (array) $val)) . ']',
                'NOT IN'  => "NOT {$field} IN [" . implode(', ', array_map(fn(mixed $v): string => $this->q($v), (array) $val)) . ']',
                default   => "{$field} {$f['operator']} " . $this->q($val),
            };
        }
        return implode(' AND ', $parts);
    }

    private function q(mixed $v): string
    {
        if (is_string($v)) {
            return '"' . addslashes($v) . '"';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        return (string) $v;
    }

    private function applyConfigSettings(IndexConfig $config): void
    {
        $s = [];
        $searchable = $config->searchableFields();
        if ($searchable !== []) {
            $s['searchableAttributes'] = $searchable;
        }
        $allFilterable = array_values(array_unique(array_merge($config->filterableFields(), $config->facetableFields())));
        if ($allFilterable !== []) {
            $s['filterableAttributes'] = $allFilterable;
        }
        $sortable = $config->sortableFields();
        if ($sortable !== []) {
            $s['sortableAttributes'] = $sortable;
        }
        if ($config->stopWords !== []) {
            $s['stopWords'] = $config->stopWords;
        }
        if ($config->synonyms !== []) {
            $s['synonyms'] = $config->synonyms;
        }
        if ($s !== []) {
            $this->updateSettings($config->name, $s);
        }
    }

    // ── Raw Queries ────────────────────────────────────────────

    public function raw(string $indexName, array $rawQuery): SearchResult
    {
        $r = $this->http->post("/indexes/{$indexName}/search", $rawQuery);
        if (!$r->isSuccess) {
            throw new QueryException("Meilisearch raw query failed [{$r->statusCode}]: {$r->body}");
        }
        $data = $r->json();
        /** @var list<array<string, mixed>> $rawHits */
        $rawHits = $data['hits'] ?? [];
        $hits = array_map(static fn(array $hit): SearchHit => new SearchHit(
            id: (string) ($hit['id'] ?? ''),
            score: (float) ($hit['_rankingScore'] ?? 1.0),
            document: $hit,
        ), $rawHits);
        return new SearchResult(
            hits: $hits,
            total: (int) ($data['estimatedTotalHits'] ?? count($hits)),
            page: 1,
            perPage: count($hits),
            took: (float) ($data['processingTimeMs'] ?? 0),
            meta: ['engine' => 'meilisearch'],
        );
    }

    // ── Suggestions ────────────────────────────────────────────

    public function suggest(string $indexName, string $prefix, int $limit = 5): array
    {
        $r = $this->http->post("/indexes/{$indexName}/search", [
            'q'     => $prefix,
            'limit' => $limit,
        ]);
        if (!$r->isSuccess) {
            return [];
        }
        $data = $r->json();
        /** @var list<array<string, mixed>> $rawHits */
        $rawHits = $data['hits'] ?? [];
        $suggestions = [];
        foreach ($rawHits as $hit) {
            foreach ($hit as $value) {
                if (is_string($value) && str_contains(strtolower($value), strtolower($prefix))) {
                    $suggestions[] = new Suggestion(text: $value, score: (float) ($hit['_rankingScore'] ?? 1.0));
                    break;
                }
            }
        }
        return array_slice($suggestions, 0, $limit);
    }
}
