<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Engine;

use MonkeysLegion\Search\Contracts\SearchEngineInterface;
use MonkeysLegion\Search\Dto\IndexConfig;
use MonkeysLegion\Search\Dto\SearchHit;
use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Dto\SearchResult;
use MonkeysLegion\Search\Dto\Suggestion;

/**
 * MonkeysLegion Framework — Search Package
 *
 * No-op engine for testing and local development without a search service.
 *
 * All write operations are silently discarded. Search always returns
 * an empty result set. Ping always succeeds.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class NullEngine implements SearchEngineInterface
{
    /** @var array<string, array<string, array<string, mixed>>> In-memory storage. */
    private array $indexes = [];

    // ── Search ─────────────────────────────────────────────────

    public function search(SearchQuery $query): SearchResult
    {
        $docs = $this->indexes[$query->indexName] ?? [];

        // Simple in-memory term matching for testing
        if ($query->term !== '') {
            $term = strtolower($query->term);
            $docs = array_filter(
                $docs,
                static function (array $doc) use ($term): bool {
                    foreach ($doc as $value) {
                        if (is_string($value) && str_contains(strtolower($value), $term)) {
                            return true;
                        }
                    }
                    return false;
                },
            );
        }

        $total = count($docs);
        $offset = $query->offset;
        $slice = array_slice(array_values($docs), $offset, $query->perPage);

        $hits = array_map(
            static fn(array $doc): SearchHit => new SearchHit(
                id: (string) ($doc['id'] ?? ''),
                score: 1.0,
                document: $doc,
            ),
            $slice,
        );

        return new SearchResult(
            hits: $hits,
            total: $total,
            page: $query->page,
            perPage: $query->perPage,
            took: 0.0,
            meta: ['engine' => 'null'],
        );
    }

    // ── Document Indexing ──────────────────────────────────────

    public function index(string $indexName, string $id, array $document): void
    {
        $this->indexes[$indexName][$id] = array_merge(['id' => $id], $document);
    }

    public function bulkIndex(string $indexName, array $documents): int
    {
        $count = 0;
        foreach ($documents as $doc) {
            $id = (string) ($doc['id'] ?? uniqid('null_', true));
            $this->indexes[$indexName][$id] = $doc;
            $count++;
        }
        return $count;
    }

    public function delete(string $indexName, string $id): void
    {
        unset($this->indexes[$indexName][$id]);
    }

    public function bulkDelete(string $indexName, array $ids): int
    {
        $count = 0;
        foreach ($ids as $id) {
            if (isset($this->indexes[$indexName][$id])) {
                unset($this->indexes[$indexName][$id]);
                $count++;
            }
        }
        return $count;
    }

    // ── Index Management ───────────────────────────────────────

    public function createIndex(IndexConfig $config): void
    {
        $this->indexes[$config->name] ??= [];
    }

    public function deleteIndex(string $indexName): void
    {
        unset($this->indexes[$indexName]);
    }

    public function indexExists(string $indexName): bool
    {
        return isset($this->indexes[$indexName]);
    }

    public function updateSettings(string $indexName, array $settings): void
    {
        // No-op — NullEngine has no settings
    }

    // ── Health & Diagnostics ───────────────────────────────────

    public function ping(): bool
    {
        return true;
    }

    public function info(): array
    {
        return [
            'engine'  => 'null',
            'version' => '1.0.0',
            'indexes' => count($this->indexes),
        ];
    }

    // ── Raw Queries ────────────────────────────────────────────

    public function raw(string $indexName, array $rawQuery): SearchResult
    {
        // NullEngine: treat raw as a basic search
        return $this->search(new SearchQuery(indexName: $indexName));
    }

    // ── Suggestions ────────────────────────────────────────────

    public function suggest(string $indexName, string $prefix, int $limit = 5): array
    {
        $docs = $this->indexes[$indexName] ?? [];
        $prefix = strtolower($prefix);
        $suggestions = [];

        foreach ($docs as $doc) {
            foreach ($doc as $value) {
                if (is_string($value) && str_starts_with(strtolower($value), $prefix)) {
                    $suggestions[] = new Suggestion(text: $value, score: 1.0);
                }
            }
            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return array_slice($suggestions, 0, $limit);
    }
}
