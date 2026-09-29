<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Engines;

use MonkeysLegion\Search\Contracts\SearchEngineInterface;
use MonkeysLegion\Search\Dto\{Facet, IndexConfig, SearchHit, SearchQuery, SearchResult, Suggestion};

/**
 * MonKeysLegion Framework — Search Package
 *
 * In-memory Null engine for testing and development.
 *
 * Stores documents in a PHP array and performs basic LIKE matching.
 * No external service required — perfect for tests and local dev.
 *
 * @copyright 2026 MonKeysCloud Team
 * @license   MIT
 */
final class NullEngine implements SearchEngineInterface
{
    /** @var array<string, array<string, array<string, mixed>>> index => [id => document] */
    private array $indexes = [];

    public function search(SearchQuery $query): SearchResult
    {
        $start = microtime(true);
        $indexName = $query->indexName;
        $documents = $this->indexes[$indexName] ?? [];

        $hits = [];
        $facets = [];
        $total = 0;

        foreach ($documents as $id => $document) {
            if ($this->matches($document, $query)) {
                $total++;
                $hits[] = new SearchHit(
                    id: (string) $id,
                    score: $this->score($document, $query->term),
                    document: $document,
                    highlights: $this->highlight($document, $query),
                );
            }
        }

        // Sort
        if (!empty($query->sorts)) {
            usort($hits, function($a, $b) use ($query) {
                foreach ($query->sorts as $sort) {
                    $field = $sort['field'];
                    $direction = $sort['direction'];
                    $aVal = $a->document[$field] ?? null;
                    $bVal = $b->document[$field] ?? null;
                    $cmp = ($aVal <=> $bVal) * ($direction->value === 'asc' ? 1 : -1);
                    if ($cmp !== 0) return $cmp;
                }
                return 0;
            });
        }

        // Paginate
        $offset = $query->offset;
        $paged = array_slice($hits, $offset, $query->perPage);

        // Build facets
        foreach ($query->facets as $field) {
            $values = [];
            foreach ($hits as $hit) {
                $val = $hit->document[$field] ?? null;
                if ($val !== null) {
                    $key = (string) $val;
                    $values[$key] = ($values[$key] ?? 0) + 1;
                }
            }
            $facets[] = new Facet($field, $values);
        }

        $took = (microtime(true) - $start) * 1000;

        return new SearchResult(
            hits: $paged,
            total: $total,
            page: $query->page,
            perPage: $query->perPage,
            facets: $facets,
            took: $took,
            meta: ['engine' => 'null'],
        );
    }

    public function index(string $indexName, string $id, array $document): void
    {
        $this->indexes[$indexName][$id] = $document;
    }

    public function bulkIndex(string $indexName, array $documents): int
    {
        $count = 0;
        foreach ($documents as $document) {
            $id = (string) ($document['id'] ?? uniqid());
            $this->index($indexName, $id, $document);
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

    public function createIndex(IndexConfig $config): void
    {
        if (!isset($this->indexes[$config->name])) {
            $this->indexes[$config->name] = [];
        }
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
        // No-op for null engine
    }

    public function ping(): bool
    {
        return true;
    }

    public function info(): array
    {
        return [
            'engine'  => 'null',
            'version' => '1.0',
            'indexes' => count($this->indexes),
            'docs'    => array_sum(array_map('count', $this->indexes)),
        ];
    }

    public function raw(string $indexName, array $rawQuery): SearchResult
    {
        // Null engine doesn't support raw queries — return empty
        return new SearchResult(
            hits: [],
            total: 0,
            page: 1,
            perPage: 20,
            meta: ['engine' => 'null', 'note' => 'raw queries not supported'],
        );
    }

    public function suggest(string $indexName, string $prefix, int $limit = 5): array
    {
        $documents = $this->indexes[$indexName] ?? [];
        $suggestions = [];
        $prefixLower = strtolower($prefix);

        foreach ($documents as $document) {
            foreach ($document as $value) {
                if (is_string($value) && str_starts_with(strtolower($value), $prefixLower)) {
                    $suggestions[] = new Suggestion(text: $value, score: 1.0);
                    if (count($suggestions) >= $limit) break 2;
                }
            }
        }

        return $suggestions;
    }

    /**
     * Check if a document matches the query filters.
     *
     * @param array<string, mixed> $document
     */
    private function matches(array $document, SearchQuery $query): bool
    {
        // Term matching (LIKE)
        if ($query->term !== '') {
            $found = false;
            $termLower = strtolower($query->term);
            foreach ($document as $value) {
                if (is_string($value) && str_contains(strtolower($value), $termLower)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) return false;
        }

        // Filter matching
        foreach ($query->filters as $filter) {
            $field = $filter['field'];
            $operator = $filter['operator'];
            $value = $filter['value'];
            $docVal = $document[$field] ?? null;

            $match = match ($operator) {
                '='      => $docVal == $value,
                '!=', '<>' => $docVal != $value,
                '>'      => $docVal > $value,
                '<'      => $docVal < $value,
                '>='     => $docVal >= $value,
                '<='     => $docVal <= $value,
                'in'     => is_array($value) && in_array($docVal, $value, true),
                'not_in' => is_array($value) && !in_array($docVal, $value, true),
                'like'   => is_string($docVal) && str_contains(strtolower($docVal), strtolower((string) $value)),
                default  => false,
            };

            if (!$match) return false;
        }

        return true;
    }

    /**
     * Calculate a simple relevance score.
     *
     * @param array<string, mixed> $document
     */
    private function score(array $document, string $term): float
    {
        if ($term === '') return 1.0;

        $score = 0.0;
        $termLower = strtolower($term);
        foreach ($document as $value) {
            if (is_string($value)) {
                $count = substr_count(strtolower($value), $termLower);
                $score += $count;
            }
        }
        return $score > 0 ? $score : 1.0;
    }

    /**
     * Build highlight snippets.
     *
     * @param array<string, mixed> $document
     * @return array<string, string>
     */
    private function highlight(array $document, SearchQuery $query): array
    {
        if (empty($query->highlightFields) || $query->term === '') return [];

        $highlights = [];
        $term = $query->term;

        foreach ($query->highlightFields as $field) {
            $value = $document[$field] ?? null;
            if (is_string($value)) {
                $highlighted = preg_replace(
                    '/(' . preg_quote($term, '/') . ')/i',
                    '<em>$1</em>',
                    $value,
                );
                if ($highlighted !== null && $highlighted !== $value) {
                    $highlights[$field] = $highlighted;
                }
            }
        }

        return $highlights;
    }
}
