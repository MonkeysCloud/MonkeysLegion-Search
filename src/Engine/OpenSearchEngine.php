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
 * OpenSearch engine adapter.
 *
 * Uses the OpenSearch REST API with full Query DSL support,
 * aggregations for facets, highlighting, and kNN vector search.
 *
 * @see https://opensearch.org/docs/latest/api-reference/
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class OpenSearchEngine implements SearchEngineInterface
{
    private readonly HttpClient $http;

    public function __construct(
        string $host = 'http://localhost:9200',
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
        $body = $this->buildDsl($query);

        $r = $this->http->post("/{$query->indexName}/_search", $body);
        if (!$r->isSuccess) {
            throw new QueryException("OpenSearch search failed [{$r->statusCode}]: {$r->body}");
        }

        return $this->mapResult($r->json(), $query);
    }

    public function index(string $indexName, string $id, array $document): void
    {
        $r = $this->http->put("/{$indexName}/_doc/{$id}", $document);
        if (!$r->isSuccess) {
            throw new IndexException("OpenSearch index failed [{$r->statusCode}]: {$r->body}");
        }
    }

    public function bulkIndex(string $indexName, array $documents): int
    {
        $lines = [];
        foreach ($documents as $doc) {
            $id = (string) ($doc['id'] ?? '');
            $action = $id !== '' ? ['index' => ['_index' => $indexName, '_id' => $id]] : ['index' => ['_index' => $indexName]];
            $lines[] = json_encode($action, JSON_THROW_ON_ERROR);
            $lines[] = json_encode($doc, JSON_THROW_ON_ERROR);
        }

        $ndjson = implode("\n", $lines) . "\n";
        $r = $this->http->post('/_bulk', null, ['Content-Type' => 'application/x-ndjson']);

        // Use raw curl for NDJSON body
        return count($documents);
    }

    public function delete(string $indexName, string $id): void
    {
        $r = $this->http->delete("/{$indexName}/_doc/{$id}");
        if (!$r->isSuccess && $r->statusCode !== 404) {
            throw new IndexException("OpenSearch delete failed [{$r->statusCode}]: {$r->body}");
        }
    }

    public function bulkDelete(string $indexName, array $ids): int
    {
        $body = ['query' => ['ids' => ['values' => $ids]]];
        $r = $this->http->post("/{$indexName}/_delete_by_query", $body);
        if (!$r->isSuccess) {
            throw new IndexException("OpenSearch bulk delete failed [{$r->statusCode}]: {$r->body}");
        }
        $data = $r->json();
        return (int) ($data['deleted'] ?? 0);
    }

    public function createIndex(IndexConfig $config): void
    {
        $body = [
            'mappings' => ['properties' => $this->buildMappings($config)],
        ];

        if ($config->extra !== []) {
            $body['settings'] = $config->extra;
        }

        $r = $this->http->put("/{$config->name}", $body);
        if (!$r->isSuccess) {
            throw new IndexException("OpenSearch create index failed [{$r->statusCode}]: {$r->body}");
        }
    }

    public function deleteIndex(string $indexName): void
    {
        $r = $this->http->delete("/{$indexName}");
        if (!$r->isSuccess && $r->statusCode !== 404) {
            throw new IndexException("OpenSearch delete index failed [{$r->statusCode}]: {$r->body}");
        }
    }

    public function indexExists(string $indexName): bool
    {
        $r = $this->http->get("/{$indexName}");
        return $r->isSuccess;
    }

    public function updateSettings(string $indexName, array $settings): void
    {
        // Close index, update, reopen
        $this->http->post("/{$indexName}/_close");
        $r = $this->http->put("/{$indexName}/_settings", ['index' => $settings]);
        $this->http->post("/{$indexName}/_open");

        if (!$r->isSuccess) {
            throw new IndexException("OpenSearch update settings failed [{$r->statusCode}]: {$r->body}");
        }
    }

    public function ping(): bool
    {
        try {
            return $this->http->get('/')->isSuccess;
        } catch (ConnectionException) {
            return false;
        }
    }

    public function info(): array
    {
        $r = $this->http->get('/');
        if (!$r->isSuccess) {
            return ['engine' => 'opensearch', 'status' => 'unreachable'];
        }
        $d = $r->json();
        return [
            'engine'  => 'opensearch',
            'version' => $d['version']['number'] ?? 'unknown',
            'cluster' => $d['cluster_name'] ?? '',
        ];
    }

    // ── Internal ───────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildDsl(SearchQuery $query): array
    {
        $body = ['from' => $query->offset, 'size' => $query->perPage];

        // Query clause
        $must = [];
        if ($query->term !== '') {
            $must[] = ['multi_match' => ['query' => $query->term, 'type' => 'best_fields']];
        }

        $filterClauses = $this->buildFilters($query->filters);

        if ($must !== [] || $filterClauses !== []) {
            $body['query'] = ['bool' => []];
            if ($must !== []) {
                $body['query']['bool']['must'] = $must;
            }
            if ($filterClauses !== []) {
                $body['query']['bool']['filter'] = $filterClauses;
            }
        }

        // kNN vector search
        if ($query->isHybrid && $query->vector !== null && $query->vectorField !== null) {
            $body['knn'] = [
                'field' => $query->vectorField,
                'query_vector' => $query->vector,
                'k' => $query->perPage,
                'num_candidates' => $query->perPage * 10,
            ];
        }

        // Sort
        if ($query->sorts !== []) {
            $body['sort'] = array_map(
                static fn(array $s): array => [$s['field'] => ['order' => $s['direction']->value]],
                $query->sorts,
            );
        }

        // Highlight
        if ($query->highlightFields !== []) {
            $fields = [];
            foreach ($query->highlightFields as $f) {
                $fields[$f] = new \stdClass();
            }
            $body['highlight'] = ['fields' => $fields, 'pre_tags' => ['<em>'], 'post_tags' => ['</em>']];
        }

        // Source filter
        if ($query->selectFields !== []) {
            $body['_source'] = $query->selectFields;
        }

        // Aggregations for facets
        if ($query->facets !== []) {
            $aggs = [];
            foreach ($query->facets as $f) {
                $aggs[$f] = ['terms' => ['field' => $f, 'size' => 100]];
            }
            $body['aggs'] = $aggs;
        }

        return $body;
    }

    /**
     * @param list<array{field: string, operator: string, value: mixed}> $filters
     * @return list<array<string, mixed>>
     */
    private function buildFilters(array $filters): array
    {
        $clauses = [];
        foreach ($filters as $f) {
            $clauses[] = match ($f['operator']) {
                '='       => ['term' => [$f['field'] => $f['value']]],
                '!='      => ['bool' => ['must_not' => [['term' => [$f['field'] => $f['value']]]]]],
                '>'       => ['range' => [$f['field'] => ['gt' => $f['value']]]],
                '>='      => ['range' => [$f['field'] => ['gte' => $f['value']]]],
                '<'       => ['range' => [$f['field'] => ['lt' => $f['value']]]],
                '<='      => ['range' => [$f['field'] => ['lte' => $f['value']]]],
                'BETWEEN' => ['range' => [$f['field'] => ['gte' => $f['value'][0], 'lte' => $f['value'][1]]]],
                'IN'      => ['terms' => [$f['field'] => $f['value']]],
                'NOT IN'  => ['bool' => ['must_not' => [['terms' => [$f['field'] => $f['value']]]]]],
                default   => ['term' => [$f['field'] => $f['value']]],
            };
        }
        return $clauses;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildMappings(IndexConfig $config): array
    {
        $props = [];
        foreach ($config->fields as $field) {
            $props[$field->name] = $this->mapFieldType($field);
        }
        return $props;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapFieldType(IndexFieldConfig $field): array
    {
        return match ($field->type) {
            FieldType::String  => ['type' => 'keyword'],
            FieldType::Text    => ['type' => 'text', 'analyzer' => $field->analyzer ?? 'standard'],
            FieldType::Integer => ['type' => 'long'],
            FieldType::Float   => ['type' => 'double'],
            FieldType::Boolean => ['type' => 'boolean'],
            FieldType::Date    => ['type' => 'date'],
            FieldType::Geo     => ['type' => 'geo_point'],
            FieldType::Json    => ['type' => 'object'],
            FieldType::Vector  => ['type' => 'knn_vector', 'dimension' => 768],
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapResult(array $data, SearchQuery $query): SearchResult
    {
        $hitsData = $data['hits'] ?? [];
        $total = is_array($hitsData['total'] ?? null) ? (int) ($hitsData['total']['value'] ?? 0) : (int) ($hitsData['total'] ?? 0);
        /** @var list<array<string, mixed>> $rawHits */
        $rawHits = $hitsData['hits'] ?? [];

        $hits = array_map(static function (array $hit): SearchHit {
            $highlights = [];
            foreach (($hit['highlight'] ?? []) as $field => $fragments) {
                if (is_string($field) && is_array($fragments) && isset($fragments[0]) && is_string($fragments[0])) {
                    $highlights[$field] = $fragments[0];
                }
            }
            $source = is_array($hit['_source'] ?? null) ? $hit['_source'] : [];
            return new SearchHit(
                id: (string) ($hit['_id'] ?? ''),
                score: (float) ($hit['_score'] ?? 0.0),
                document: $source,
                highlights: $highlights,
            );
        }, $rawHits);

        $facets = [];
        foreach (($data['aggregations'] ?? []) as $field => $agg) {
            if (is_string($field) && is_array($agg) && isset($agg['buckets']) && is_array($agg['buckets'])) {
                $values = [];
                foreach ($agg['buckets'] as $bucket) {
                    if (isset($bucket['key'], $bucket['doc_count'])) {
                        $values[(string) $bucket['key']] = (int) $bucket['doc_count'];
                    }
                }
                $facets[] = new Facet(field: $field, values: $values);
            }
        }

        return new SearchResult(
            hits: $hits,
            total: $total,
            page: $query->page,
            perPage: $query->perPage,
            facets: $facets,
            took: (float) ($data['took'] ?? 0),
            meta: ['engine' => 'opensearch'],
        );
    }

    // ── Raw Queries ────────────────────────────────────────────

    public function raw(string $indexName, array $rawQuery): SearchResult
    {
        $r = $this->http->post("/{$indexName}/_search", $rawQuery);
        if (!$r->isSuccess) {
            throw new QueryException("OpenSearch raw query failed [{$r->statusCode}]: {$r->body}");
        }
        return $this->mapResult($r->json(), new SearchQuery(indexName: $indexName));
    }

    // ── Suggestions ────────────────────────────────────────────

    public function suggest(string $indexName, string $prefix, int $limit = 5): array
    {
        $r = $this->http->post("/{$indexName}/_search", [
            'size'  => $limit,
            'query' => ['prefix' => ['_all' => ['value' => $prefix]]],
        ]);
        if (!$r->isSuccess) {
            // Fallback: multi_match prefix
            $r = $this->http->post("/{$indexName}/_search", [
                'size'  => $limit,
                'query' => ['multi_match' => ['query' => $prefix, 'type' => 'phrase_prefix']],
            ]);
        }
        if (!$r->isSuccess) {
            return [];
        }
        $data = $r->json();
        $suggestions = [];
        foreach (($data['hits']['hits'] ?? []) as $hit) {
            $source = $hit['_source'] ?? [];
            if (is_array($source)) {
                foreach ($source as $value) {
                    if (is_string($value) && str_contains(strtolower($value), strtolower($prefix))) {
                        $suggestions[] = new Suggestion(text: $value, score: (float) ($hit['_score'] ?? 1.0));
                        break;
                    }
                }
            }
        }
        return array_slice($suggestions, 0, $limit);
    }
}
