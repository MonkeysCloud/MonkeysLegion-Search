<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Engines;

use MonkeysLegion\Search\Contracts\SearchEngineInterface;
use MonkeysLegion\Search\Dto\{Facet, IndexConfig, SearchHit, SearchQuery, SearchResult, Suggestion};

/**
 * MonKeysLegion Framework — Search Package
 *
 * Meilisearch engine adapter — HTTP API client (no SDK dependency).
 *
 * Communicates with Meilisearch via its REST API using cURL.
 * Requires a running Meilisearch instance (https://www.meilisearch.com).
 *
 * @copyright 2026 MonKeysCloud Team
 * @license   MIT
 */
final class MeilisearchEngine implements SearchEngineInterface
{
    public function __construct(
        private readonly string $host = 'http://localhost:7700',
        private readonly string $apiKey = '',
    ) {}

    public function search(SearchQuery $query): SearchResult
    {
        $start = microtime(true);

        $body = [
            'q' => $query->term,
            'offset' => $query->offset,
            'limit' => $query->perPage,
        ];

        // Filters
        if (!empty($query->filters)) {
            $body['filter'] = array_map(
                fn($f) => $f['field'] . ' ' . $this->mapOperator($f['operator']) . ' ' . $this->formatValue($f['value']),
                $query->filters,
            );
        }

        // Sort
        if (!empty($query->sorts)) {
            $body['sort'] = array_map(
                fn($s) => $s['field'] . ':' . $s['direction']->value,
                $query->sorts,
            );
        }

        // Facets
        if (!empty($query->facets)) {
            $body['facets'] = $query->facets;
        }

        // Highlight
        if (!empty($query->highlightFields)) {
            $body['attributesToHighlight'] = $query->highlightFields;
        }

        // Select fields
        if (!empty($query->selectFields)) {
            $body['attributesToRetrieve'] = $query->selectFields;
        }

        $response = $this->httpPost("/indexes/{$query->indexName}/search", $body);

        $hits = [];
        foreach ($response['hits'] ?? [] as $hit) {
            $id = (string) ($hit['id'] ?? '');
            $document = $hit['_formatted'] ?? $hit;
            // Remove Meilisearch internal keys
            unset($document['_formatted'], $document['_rankingScore']);
            $highlights = $hit['_formatted'] ?? [];

            $hits[] = new SearchHit(
                id: $id,
                score: (float) ($hit['_rankingScore'] ?? 1.0),
                document: $document,
                highlights: array_intersect_key($highlights, array_flip($query->highlightFields)),
            );
        }

        // Build facets
        $facets = [];
        if (isset($response['facetDistribution'])) {
            foreach ($response['facetDistribution'] as $field => $values) {
                $facets[] = new Facet($field, $values);
            }
        }

        $took = (float) ($response['processingTimeMs'] ?? (microtime(true) - $start) * 1000);

        return new SearchResult(
            hits: $hits,
            total: $response['estimatedTotalHits'] ?? count($hits),
            page: $query->page,
            perPage: $query->perPage,
            facets: $facets,
            took: $took,
            meta: ['engine' => 'meilisearch'],
        );
    }

    public function index(string $indexName, string $id, array $document): void
    {
        $document['id'] = $id;
        $this->httpPut("/indexes/{$indexName}/documents", [$document]);
    }

    public function bulkIndex(string $indexName, array $documents): int
    {
        $this->httpPut("/indexes/{$indexName}/documents", $documents);
        return count($documents);
    }

    public function delete(string $indexName, string $id): void
    {
        $this->httpDelete("/indexes/{$indexName}/documents/{$id}");
    }

    public function bulkDelete(string $indexName, array $ids): int
    {
        $this->httpPost("/indexes/{$indexName}/documents/delete-batch", $ids);
        return count($ids);
    }

    public function createIndex(IndexConfig $config): void
    {
        $this->httpPost('/indexes', [
            'uid'        => $config->name,
            'primaryKey' => $config->primaryKey,
        ]);
    }

    public function deleteIndex(string $indexName): void
    {
        $this->httpDelete("/indexes/{$indexName}");
    }

    public function indexExists(string $indexName): bool
    {
        try {
            $this->httpGet("/indexes/{$indexName}");
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function updateSettings(string $indexName, array $settings): void
    {
        $this->httpPatch("/indexes/{$indexName}/settings", $settings);
    }

    public function ping(): bool
    {
        try {
            $response = $this->httpGet('/health');
            return ($response['status'] ?? '') === 'available';
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function info(): array
    {
        return $this->httpGet('/version');
    }

    public function raw(string $indexName, array $rawQuery): SearchResult
    {
        $response = $this->httpPost("/indexes/{$indexName}/search", $rawQuery);

        $hits = [];
        foreach ($response['hits'] ?? [] as $hit) {
            $hits[] = new SearchHit(
                id: (string) ($hit['id'] ?? ''),
                score: (float) ($hit['_rankingScore'] ?? 1.0),
                document: $hit,
            );
        }

        return new SearchResult(
            hits: $hits,
            total: $response['estimatedTotalHits'] ?? count($hits),
            page: 1,
            perPage: count($hits),
            meta: ['engine' => 'meilisearch', 'raw' => true],
        );
    }

    public function suggest(string $indexName, string $prefix, int $limit = 5): array
    {
        $response = $this->httpPost("/indexes/{$indexName}/search", [
            'q' => $prefix,
            'limit' => $limit,
            'attributesToRetrieve' => ['name'],
        ]);

        $suggestions = [];
        foreach ($response['hits'] ?? [] as $hit) {
            $suggestions[] = new Suggestion(
                text: (string) ($hit['name'] ?? ''),
                score: (float) ($hit['_rankingScore'] ?? 1.0),
            );
        }
        return $suggestions;
    }

    // ── HTTP helpers ────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function httpGet(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function httpPost(string $path, array $body = []): array
    {
        return $this->request('POST', $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function httpPut(string $path, array $body = []): array
    {
        return $this->request('PUT', $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function httpPatch(string $path, array $body = []): array
    {
        return $this->request('PATCH', $path, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function httpDelete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->host . $path);
        $headers = ['Content-Type: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Meilisearch HTTP {$method} failed: {$error}");
        }

        $data = json_decode((string) $response, true) ?? [];

        if ($httpCode >= 400) {
            $message = $data['message'] ?? $data['error'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("Meilisearch error: {$message}");
        }

        return $data;
    }

    private function mapOperator(string $operator): string
    {
        return match ($operator) {
            '='      => '=',
            '!=', '<>' => '!=',
            '>'      => '>',
            '<'      => '<',
            '>='     => '>=',
            '<='     => '<=',
            'in'     => 'IN',
            'like'   => 'LIKE',
            default  => '=',
        };
    }

    private function formatValue(mixed $value): string
    {
        if (is_string($value)) return '"' . addslashes($value) . '"';
        if (is_array($value)) return '[' . implode(', ', array_map($this->formatValue(...), $value)) . ']';
        return (string) $value;
    }
}
