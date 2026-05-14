<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Dto;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Immutable DTO representing the full result of a search query.
 *
 * Contains hits, pagination metadata, facet distributions,
 * and engine timing information.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class SearchResult
{
    /**
     * @param list<SearchHit>              $hits          Matched documents.
     * @param int                          $total         Total matching documents.
     * @param int                          $page          Current page (1-indexed).
     * @param int                          $perPage       Results per page.
     * @param list<Facet>                  $facets        Facet distributions.
     * @param float                        $took          Query duration in milliseconds.
     * @param array<string, mixed>         $meta          Engine-specific metadata.
     * @param list<AggregationResult>      $aggregations  Aggregation results.
     * @param list<Suggestion>             $suggestions   Autocomplete suggestions.
     */
    public function __construct(
        public readonly array $hits,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly array $facets = [],
        public readonly float $took = 0.0,
        public readonly array $meta = [],
        public readonly array $aggregations = [],
        public readonly array $suggestions = [],
    ) {}

    /**
     * Total number of pages.
     */
    public int $lastPage {
        get => $this->perPage > 0 ? max(1, (int) ceil($this->total / $this->perPage)) : 1;
    }

    /**
     * Whether there are more pages after the current one.
     */
    public bool $hasMore {
        get => $this->page < $this->lastPage;
    }

    /**
     * Number of hits on this page.
     */
    public int $count {
        get => count($this->hits);
    }

    /**
     * Whether the result set is empty.
     */
    public bool $isEmpty {
        get => $this->total === 0;
    }
}
