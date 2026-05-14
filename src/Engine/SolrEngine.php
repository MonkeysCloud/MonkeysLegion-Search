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
 * Apache Solr engine adapter.
 *
 * Communicates with Solr's JSON API (/select, /update, /schema).
 * Supports standard query, faceting, highlighting, and filter queries.
 *
 * @see https://solr.apache.org/guide/solr/latest/
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class SolrEngine implements SearchEngineInterface
{
    private readonly HttpClient $http;

    public function __construct(
        string $host = 'http://localhost:8983',
        private readonly string $collection = 'default',
        string $username = '',
        string $password = '',
    ) {
        $headers = ['Content-Type' => 'application/json'];
        if ($username !== '' && $password !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode("{$username}:{$password}");
        }
        $this->http = new HttpClient(baseUrl: $host, defaultHeaders: $headers);
    }

    public function search(SearchQuery $query): SearchResult
    {
        $solrIndex = $query->indexName ?: $this->collection;
        $params = [
            'q'     => $query->term !== '' ? $query->term : '*:*',
            'start' => $query->offset,
            'rows'  => $query->perPage,
            'wt'    => 'json',
        ];

        if ($query->filters !== []) {
            $params['fq'] = $this->buildFilterQueries($query->filters);
        }
        if ($query->sorts !== []) {
            $params['sort'] = implode(', ', array_map(
                static fn(array $s): string => "{$s['field']} {$s['direction']->value}",
                $query->sorts,
            ));
        }
        if ($query->facets !== []) {
            $params['facet'] = 'true';
            $params['facet.field'] = $query->facets;
        }
        if ($query->highlightFields !== []) {
            $params['hl'] = 'true';
            $params['hl.fl'] = implode(',', $query->highlightFields);
            $params['hl.simple.pre'] = '<em>';
            $params['hl.simple.post'] = '</em>';
        }
        if ($query->selectFields !== []) {
            $params['fl'] = implode(',', $query->selectFields);
        }

        $r = $this->http->get("/solr/{$solrIndex}/select", $params);
        if (!$r->isSuccess) {
            throw new QueryException("Solr search failed [{$r->statusCode}]: {$r->body}");
        }

        return $this->mapResult($r->json(), $query);
    }

    public function index(string $indexName, string $id, array $document): void
    {
        $document['id'] = $id;
        $solrIndex = $indexName ?: $this->collection;
        $r = $this->http->post("/solr/{$solrIndex}/update/json/docs?commit=true", $document);
        if (!$r->isSuccess) {
            throw new IndexException("Solr index failed [{$r->statusCode}]: {$r->body}");
        }
    }

    public function bulkIndex(string $indexName, array $documents): int
    {
        $solrIndex = $indexName ?: $this->collection;
        $r = $this->http->post("/solr/{$solrIndex}/update?commit=true", $documents);
        if (!$r->isSuccess) {
            throw new IndexException("Solr bulk index failed [{$r->statusCode}]: {$r->body}");
        }
        return count($documents);
    }

    public function delete(string $indexName, string $id): void
    {
        $solrIndex = $indexName ?: $this->collection;
        $r = $this->http->post("/solr/{$solrIndex}/update?commit=true", [
            'delete' => ['id' => $id],
        ]);
        if (!$r->isSuccess) {
            throw new IndexException("Solr delete failed [{$r->statusCode}]: {$r->body}");
        }
    }

    public function bulkDelete(string $indexName, array $ids): int
    {
        $solrIndex = $indexName ?: $this->collection;
        $deletes = array_map(static fn(string $id): array => ['id' => $id], $ids);
        $r = $this->http->post("/solr/{$solrIndex}/update?commit=true", [
            'delete' => $deletes,
        ]);
        if (!$r->isSuccess) {
            throw new IndexException("Solr bulk delete failed [{$r->statusCode}]: {$r->body}");
        }
        return count($ids);
    }

    public function createIndex(IndexConfig $config): void
    {
        // Create Solr collection via Collections API
        $r = $this->http->get('/solr/admin/collections', [
            'action'            => 'CREATE',
            'name'              => $config->name,
            'numShards'         => 1,
            'replicationFactor' => 1,
            'wt'                => 'json',
        ]);

        if (!$r->isSuccess) {
            throw new IndexException("Solr create collection failed [{$r->statusCode}]: {$r->body}");
        }

        // Add fields via Schema API
        foreach ($config->fields as $field) {
            $this->addSchemaField($config->name, $field);
        }
    }

    public function deleteIndex(string $indexName): void
    {
        $r = $this->http->get('/solr/admin/collections', [
            'action' => 'DELETE',
            'name'   => $indexName,
            'wt'     => 'json',
        ]);
        if (!$r->isSuccess && $r->statusCode !== 404) {
            throw new IndexException("Solr delete collection failed [{$r->statusCode}]: {$r->body}");
        }
    }

    public function indexExists(string $indexName): bool
    {
        $r = $this->http->get('/solr/admin/collections', ['action' => 'LIST', 'wt' => 'json']);
        if (!$r->isSuccess) {
            return false;
        }
        $data = $r->json();
        $collections = $data['collections'] ?? [];
        return is_array($collections) && in_array($indexName, $collections, true);
    }

    public function updateSettings(string $indexName, array $settings): void
    {
        // Solr settings are managed via configsets; update fields via Schema API
        foreach ($settings as $key => $value) {
            if ($key === 'fields' && is_array($value)) {
                foreach ($value as $fieldDef) {
                    if (is_array($fieldDef)) {
                        $this->http->post("/solr/{$indexName}/schema", [
                            'replace-field' => $fieldDef,
                        ]);
                    }
                }
            }
        }
    }

    public function ping(): bool
    {
        try {
            $r = $this->http->get("/solr/{$this->collection}/admin/ping", ['wt' => 'json']);
            return $r->isSuccess;
        } catch (ConnectionException) {
            return false;
        }
    }

    public function info(): array
    {
        $r = $this->http->get('/solr/admin/info/system', ['wt' => 'json']);
        if (!$r->isSuccess) {
            return ['engine' => 'solr', 'status' => 'unreachable'];
        }
        $d = $r->json();
        return [
            'engine'  => 'solr',
            'version' => $d['lucene']['solr-spec-version'] ?? 'unknown',
        ];
    }

    // ── Internal ───────────────────────────────────────────────

    /**
     * @param list<array{field: string, operator: string, value: mixed}> $filters
     * @return list<string>
     */
    private function buildFilterQueries(array $filters): array
    {
        $fqs = [];
        foreach ($filters as $f) {
            $field = $f['field'];
            $val = $f['value'];
            $fqs[] = match ($f['operator']) {
                '='       => "{$field}:" . $this->q($val),
                '!='      => "-{$field}:" . $this->q($val),
                '>'       => "{$field}:{" . $this->q($val) . ' TO *}',
                '>='      => "{$field}:[" . $this->q($val) . ' TO *]',
                '<'       => "{$field}:{* TO " . $this->q($val) . '}',
                '<='      => "{$field}:[* TO " . $this->q($val) . ']',
                'BETWEEN' => "{$field}:[{$val[0]} TO {$val[1]}]",
                'IN'      => "{$field}:(" . implode(' OR ', array_map(fn(mixed $v): string => $this->q($v), (array) $val)) . ')',
                'NOT IN'  => "-{$field}:(" . implode(' OR ', array_map(fn(mixed $v): string => $this->q($v), (array) $val)) . ')',
                default   => "{$field}:" . $this->q($val),
            };
        }
        return $fqs;
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

    private function addSchemaField(string $collection, IndexFieldConfig $field): void
    {
        $def = [
            'name'    => $field->name,
            'type'    => $this->mapSolrType($field->type),
            'indexed' => $field->searchable || $field->filterable,
            'stored'  => true,
        ];

        $this->http->post("/solr/{$collection}/schema", ['add-field' => $def]);
    }

    private function mapSolrType(FieldType $type): string
    {
        return match ($type) {
            FieldType::String  => 'string',
            FieldType::Text    => 'text_general',
            FieldType::Integer => 'plong',
            FieldType::Float   => 'pdouble',
            FieldType::Boolean => 'boolean',
            FieldType::Date    => 'pdate',
            FieldType::Geo     => 'location',
            FieldType::Json    => 'text_general',
            FieldType::Vector  => 'knn_vector',
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapResult(array $data, SearchQuery $query): SearchResult
    {
        $response = $data['response'] ?? [];
        $total = (int) ($response['numFound'] ?? 0);
        /** @var list<array<string, mixed>> $docs */
        $docs = $response['docs'] ?? [];

        $hlData = $data['highlighting'] ?? [];
        $hits = array_map(static function (array $doc) use ($hlData, $query): SearchHit {
            $id = (string) ($doc['id'] ?? '');
            $highlights = [];
            if (is_array($hlData) && isset($hlData[$id]) && is_array($hlData[$id])) {
                foreach ($query->highlightFields as $f) {
                    if (isset($hlData[$id][$f][0]) && is_string($hlData[$id][$f][0])) {
                        $highlights[$f] = $hlData[$id][$f][0];
                    }
                }
            }
            $score = (float) ($doc['score'] ?? 0.0);
            unset($doc['score']);
            return new SearchHit(id: $id, score: $score, document: $doc, highlights: $highlights);
        }, $docs);

        $facets = [];
        $facetFields = $data['facet_counts']['facet_fields'] ?? [];
        if (is_array($facetFields)) {
            foreach ($facetFields as $field => $pairs) {
                if (is_string($field) && is_array($pairs)) {
                    $values = [];
                    for ($i = 0, $len = count($pairs); $i < $len - 1; $i += 2) {
                        if (is_string($pairs[$i])) {
                            $values[$pairs[$i]] = (int) ($pairs[$i + 1] ?? 0);
                        }
                    }
                    $facets[] = new Facet(field: $field, values: $values);
                }
            }
        }

        $qTime = (float) ($data['responseHeader']['QTime'] ?? 0);

        return new SearchResult(
            hits: $hits,
            total: $total,
            page: $query->page,
            perPage: $query->perPage,
            facets: $facets,
            took: $qTime,
            meta: ['engine' => 'solr'],
        );
    }

    // ── Raw Queries ────────────────────────────────────────────

    public function raw(string $indexName, array $rawQuery): SearchResult
    {
        $solrIndex = $indexName ?: $this->collection;
        $r = $this->http->get("/solr/{$solrIndex}/select", array_merge($rawQuery, ['wt' => 'json']));
        if (!$r->isSuccess) {
            throw new QueryException("Solr raw query failed [{$r->statusCode}]: {$r->body}");
        }
        return $this->mapResult($r->json(), new SearchQuery(indexName: $indexName));
    }

    // ── Suggestions ────────────────────────────────────────────

    public function suggest(string $indexName, string $prefix, int $limit = 5): array
    {
        $solrIndex = $indexName ?: $this->collection;
        $r = $this->http->get("/solr/{$solrIndex}/suggest", [
            'suggest.q'          => $prefix,
            'suggest'            => 'true',
            'suggest.count'      => $limit,
            'suggest.dictionary' => 'default',
            'wt'                 => 'json',
        ]);
        if (!$r->isSuccess) {
            // Fallback: regular search with prefix
            $r = $this->http->get("/solr/{$solrIndex}/select", [
                'q'    => "{$prefix}*",
                'rows' => $limit,
                'wt'   => 'json',
            ]);
            if (!$r->isSuccess) {
                return [];
            }
            $data = $r->json();
            $suggestions = [];
            foreach (($data['response']['docs'] ?? []) as $doc) {
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

        $data = $r->json();
        $suggestions = [];
        $suggest = $data['suggest']['default'][$prefix]['suggestions'] ?? [];
        if (is_array($suggest)) {
            foreach ($suggest as $s) {
                if (isset($s['term']) && is_string($s['term'])) {
                    $suggestions[] = new Suggestion(
                        text: $s['term'],
                        score: (float) ($s['weight'] ?? 1.0),
                    );
                }
            }
        }
        return array_slice($suggestions, 0, $limit);
    }
}
