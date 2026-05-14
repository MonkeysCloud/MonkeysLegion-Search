<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Dto;

use MonkeysLegion\Search\Enum\SortDirection;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Immutable DTO representing a fully-configured search query.
 *
 * Built by the fluent Builder and consumed by engine adapters.
 * Supports full-text search, filters, facets, sorting, pagination,
 * highlighting, and hybrid vector queries.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class SearchQuery
{
    /**
     * @param string                                     $indexName      Target index.
     * @param string                                     $term           Full-text search term.
     * @param list<array{field: string, operator: string, value: mixed}> $filters Filter clauses.
     * @param list<string>                               $facets         Fields to facet.
     * @param list<array{field: string, direction: SortDirection}> $sorts Sort criteria.
     * @param int                                        $page           Page number (1-indexed).
     * @param int                                        $perPage        Results per page.
     * @param list<string>                               $highlightFields Fields to highlight.
     * @param list<string>                               $selectFields   Fields to return.
     * @param list<float>|null                           $vector         Vector for hybrid search.
     * @param string|null                                $vectorField    Field containing vectors.
     * @param float                                      $hybridWeight   0.0=BM25, 1.0=vector.
     * @param array<string, mixed>                       $extra          Engine-specific options.
     * @param float|null                                 $geoLat         Geo latitude for distance search.
     * @param float|null                                 $geoLng         Geo longitude for distance search.
     * @param float|null                                 $geoRadius      Radius in km for geo filter.
     * @param string|null                                $geoField       Field containing geo data.
     * @param bool                                       $geoSort        Sort by distance.
     * @param list<Aggregation>                          $aggregations   Aggregation requests.
     * @param string|null                                $suggestTerm    Autocomplete prefix.
     * @param int                                        $suggestLimit   Max suggestions.
     */
    public function __construct(
        public readonly string $indexName,
        public readonly string $term = '',
        public readonly array $filters = [],
        public readonly array $facets = [],
        public readonly array $sorts = [],
        public readonly int $page = 1,
        public readonly int $perPage = 20,
        public readonly array $highlightFields = [],
        public readonly array $selectFields = [],
        public readonly ?array $vector = null,
        public readonly ?string $vectorField = null,
        public readonly float $hybridWeight = 0.5,
        public readonly array $extra = [],
        public readonly ?float $geoLat = null,
        public readonly ?float $geoLng = null,
        public readonly ?float $geoRadius = null,
        public readonly ?string $geoField = null,
        public readonly bool $geoSort = false,
        public readonly array $aggregations = [],
        public readonly ?string $suggestTerm = null,
        public readonly int $suggestLimit = 5,
    ) {}

    /**
     * Calculate the zero-based offset for pagination.
     */
    public int $offset {
        get => ($this->page - 1) * $this->perPage;
    }

    /**
     * Whether this query includes a vector component.
     */
    public bool $isHybrid {
        get => $this->vector !== null && $this->vectorField !== null;
    }

    /**
     * Whether this query includes a geo-distance component.
     */
    public bool $isGeo {
        get => $this->geoLat !== null && $this->geoLng !== null;
    }
}
