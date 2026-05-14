<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Query;

use MonkeysLegion\Search\Contracts\QueryBuilderInterface;
use MonkeysLegion\Search\Contracts\SearchEngineInterface;
use MonkeysLegion\Search\Contracts\SearchScopeInterface;
use MonkeysLegion\Search\Dto\Aggregation;
use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Dto\SearchResult;
use MonkeysLegion\Search\Enum\SortDirection;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Fluent query builder for constructing search queries.
 *
 * Accumulates query parameters through a chainable API,
 * then builds an immutable SearchQuery DTO on `get()`.
 *
 * ```php
 * $results = $builder
 *     ->query('wireless headphones')
 *     ->where('category', '=', 'electronics')
 *     ->whereBetween('price', 10.0, 200.0)
 *     ->facet('brand', 'category')
 *     ->sortBy('price', SortDirection::Asc)
 *     ->highlight('name', 'description')
 *     ->page(1, perPage: 20)
 *     ->get();
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class Builder implements QueryBuilderInterface
{
    private string $term = '';

    /** @var list<array{field: string, operator: string, value: mixed}> */
    private array $filters = [];

    /** @var list<string> */
    private array $facets = [];

    /** @var list<array{field: string, direction: SortDirection}> */
    private array $sorts = [];

    private int $page = 1;
    private int $perPage = 20;

    /** @var list<string> */
    private array $highlightFields = [];

    /** @var list<string> */
    private array $selectFields = [];

    /** @var list<float>|null */
    private ?array $vector = null;
    private ?string $vectorField = null;
    private float $hybridWeight = 0.5;

    /** @var array<string, mixed> */
    private array $extra = [];

    // ── Geo search ─────────────────────────────────────────
    private ?float $geoLat = null;
    private ?float $geoLng = null;
    private ?float $geoRadius = null;
    private ?string $geoField = null;
    private bool $geoSort = false;

    // ── Aggregations ───────────────────────────────────────
    /** @var list<Aggregation> */
    private array $aggregations = [];

    // ── Suggest ────────────────────────────────────────────
    private ?string $suggestTerm = null;
    private int $suggestLimit = 5;

    public function __construct(
        private readonly SearchEngineInterface $engine,
        private readonly string $indexName,
    ) {}

    /**
     * Set the full-text search query string.
     */
    public function query(string $term): static
    {
        $this->term = $term;
        return $this;
    }

    /**
     * Add a filter clause.
     *
     * Supported operators: =, !=, >, >=, <, <=
     */
    public function where(string $field, string $operator, mixed $value): static
    {
        $this->filters[] = [
            'field'    => $field,
            'operator' => $operator,
            'value'    => $value,
        ];
        return $this;
    }

    /**
     * Add an inclusive range filter.
     */
    public function whereBetween(string $field, mixed $min, mixed $max): static
    {
        $this->filters[] = [
            'field'    => $field,
            'operator' => 'BETWEEN',
            'value'    => [$min, $max],
        ];
        return $this;
    }

    /**
     * Add an IN filter.
     *
     * @param list<mixed> $values
     */
    public function whereIn(string $field, array $values): static
    {
        $this->filters[] = [
            'field'    => $field,
            'operator' => 'IN',
            'value'    => $values,
        ];
        return $this;
    }

    /**
     * Add a NOT IN filter.
     *
     * @param list<mixed> $values
     */
    public function whereNotIn(string $field, array $values): static
    {
        $this->filters[] = [
            'field'    => $field,
            'operator' => 'NOT IN',
            'value'    => $values,
        ];
        return $this;
    }

    /**
     * Request facets for the given fields.
     */
    public function facet(string ...$fields): static
    {
        $this->facets = array_values(array_unique(
            array_merge($this->facets, $fields),
        ));
        return $this;
    }

    /**
     * Add a sort criterion.
     */
    public function sortBy(
        string $field,
        SortDirection $direction = SortDirection::Asc,
    ): static {
        $this->sorts[] = [
            'field'     => $field,
            'direction' => $direction,
        ];
        return $this;
    }

    /**
     * Set pagination parameters.
     */
    public function page(int $page, int $perPage = 20): static
    {
        $this->page = max(1, $page);
        $this->perPage = max(1, min(1000, $perPage));
        return $this;
    }

    /**
     * Enable highlighting for the given fields.
     */
    public function highlight(string ...$fields): static
    {
        $this->highlightFields = array_values(array_unique(
            array_merge($this->highlightFields, $fields),
        ));
        return $this;
    }

    /**
     * Limit which fields are returned in result documents.
     *
     * @param list<string> $fields
     */
    public function select(array $fields): static
    {
        $this->selectFields = $fields;
        return $this;
    }

    /**
     * Set a vector query for hybrid search.
     *
     * @param list<float> $vector       Query embedding vector.
     * @param string      $vectorField  Field containing document vectors.
     * @param float       $hybridWeight Blend weight (0.0 = pure BM25, 1.0 = pure vector).
     */
    public function vectorQuery(
        array $vector,
        string $vectorField,
        float $hybridWeight = 0.5,
    ): static {
        $this->vector = $vector;
        $this->vectorField = $vectorField;
        $this->hybridWeight = max(0.0, min(1.0, $hybridWeight));
        return $this;
    }

    /**
     * Pass engine-specific options.
     *
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        $this->extra = array_merge($this->extra, $options);
        return $this;
    }

    // ── Geo Search ──────────────────────────────────────────

    /**
     * Filter by geo-distance from a point.
     *
     * @param float       $latitude  Center latitude.
     * @param float       $longitude Center longitude.
     * @param string|null $geoField  Field containing geo coordinates.
     */
    public function near(float $latitude, float $longitude, ?string $geoField = null): static
    {
        $this->geoLat = $latitude;
        $this->geoLng = $longitude;
        $this->geoField = $geoField ?? $this->geoField ?? '_geo';
        return $this;
    }

    /**
     * Limit results to within a radius (km).
     */
    public function withinRadius(float $distanceKm): static
    {
        $this->geoRadius = $distanceKm;
        return $this;
    }

    /**
     * Sort results by distance from a point.
     */
    public function sortByDistance(float $latitude, float $longitude, ?string $geoField = null): static
    {
        $this->geoLat = $latitude;
        $this->geoLng = $longitude;
        $this->geoField = $geoField ?? $this->geoField ?? '_geo';
        $this->geoSort = true;
        return $this;
    }

    // ── Aggregations ────────────────────────────────────────

    /**
     * Add an aggregation request.
     *
     * @param string               $name    Aggregation result name.
     * @param string               $type    Type: sum, avg, min, max, cardinality, histogram, date_histogram, terms.
     * @param string               $field   Field to aggregate on.
     * @param array<string, mixed> $options Extra options (interval, size, etc.).
     */
    public function aggregate(
        string $name,
        string $type,
        string $field,
        array $options = [],
    ): static {
        $this->aggregations[] = new Aggregation($name, $type, $field, $options);
        return $this;
    }

    // ── Autocomplete / Suggest ──────────────────────────────

    /**
     * Set a suggest/autocomplete prefix query.
     */
    public function suggest(string $prefix, int $limit = 5): static
    {
        $this->suggestTerm = $prefix;
        $this->suggestLimit = $limit;
        return $this;
    }

    // ── Search Scopes ───────────────────────────────────────

    /**
     * Apply a reusable search scope.
     */
    public function scope(SearchScopeInterface $scope): static
    {
        $scope->apply($this);
        return $this;
    }

    // ── Cursor / Lazy Iteration ─────────────────────────────

    /**
     * Iterate over all results lazily using cursor-based pagination.
     *
     * @param int $chunkSize Results fetched per request.
     *
     * @return LazyResults<SearchQuery>
     */
    public function cursor(int $chunkSize = 100): LazyResults
    {
        return new LazyResults($this->engine, $this->toQuery(), $chunkSize);
    }

    // ── Execution ───────────────────────────────────────────

    /**
     * Build the immutable SearchQuery DTO and execute the search.
     */
    public function get(): SearchResult
    {
        return $this->engine->search($this->toQuery());
    }

    /**
     * Build the immutable SearchQuery DTO without executing.
     */
    public function toQuery(): SearchQuery
    {
        return new SearchQuery(
            indexName: $this->indexName,
            term: $this->term,
            filters: $this->filters,
            facets: $this->facets,
            sorts: $this->sorts,
            page: $this->page,
            perPage: $this->perPage,
            highlightFields: $this->highlightFields,
            selectFields: $this->selectFields,
            vector: $this->vector,
            vectorField: $this->vectorField,
            hybridWeight: $this->hybridWeight,
            extra: $this->extra,
            geoLat: $this->geoLat,
            geoLng: $this->geoLng,
            geoRadius: $this->geoRadius,
            geoField: $this->geoField,
            geoSort: $this->geoSort,
            aggregations: $this->aggregations,
            suggestTerm: $this->suggestTerm,
            suggestLimit: $this->suggestLimit,
        );
    }
}
