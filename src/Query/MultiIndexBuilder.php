<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Query;

use MonkeysLegion\Search\Contracts\SearchEngineInterface;
use MonkeysLegion\Search\Dto\SearchHit;
use MonkeysLegion\Search\Dto\SearchResult;
use MonkeysLegion\Search\Enum\SortDirection;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Multi-index search builder.
 *
 * Searches across multiple indexes and merges results
 * with re-ranking by score.
 *
 * ```php
 * $results = $search->multiIndex(['products', 'articles', 'categories'])
 *     ->query('laptop')
 *     ->page(1, perPage: 20)
 *     ->get();
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class MultiIndexBuilder
{
    private string $term = '';

    /** @var list<array{field: string, operator: string, value: mixed}> */
    private array $filters = [];

    /** @var list<array{field: string, direction: SortDirection}> */
    private array $sorts = [];

    private int $page = 1;
    private int $perPage = 20;

    /** @var list<string> */
    private array $highlightFields = [];

    /**
     * @param SearchEngineInterface $engine      Engine to search with.
     * @param list<string>          $indexNames  Indexes to search across.
     */
    public function __construct(
        private readonly SearchEngineInterface $engine,
        private readonly array $indexNames,
    ) {}

    /**
     * Set the search term.
     */
    public function query(string $term): static
    {
        $this->term = $term;
        return $this;
    }

    /**
     * Add a filter clause.
     */
    public function where(string $field, string $operator, mixed $value): static
    {
        $this->filters[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        return $this;
    }

    /**
     * Sort results.
     */
    public function sortBy(string $field, SortDirection $direction = SortDirection::Asc): static
    {
        $this->sorts[] = ['field' => $field, 'direction' => $direction];
        return $this;
    }

    /**
     * Set pagination.
     */
    public function page(int $page, int $perPage = 20): static
    {
        $this->page = max(1, $page);
        $this->perPage = max(1, min(1000, $perPage));
        return $this;
    }

    /**
     * Enable highlighting.
     */
    public function highlight(string ...$fields): static
    {
        $this->highlightFields = array_values(array_unique(
            array_merge($this->highlightFields, $fields),
        ));
        return $this;
    }

    /**
     * Execute search across all indexes, merge and re-rank results.
     */
    public function get(): SearchResult
    {
        /** @var list<SearchHit> $allHits */
        $allHits = [];
        $totalOverall = 0;
        $totalTook = 0.0;

        // Search each index
        foreach ($this->indexNames as $indexName) {
            $builder = new Builder($this->engine, $indexName);

            if ($this->term !== '') {
                $builder->query($this->term);
            }

            foreach ($this->filters as $filter) {
                $builder->where($filter['field'], $filter['operator'], $filter['value']);
            }

            foreach ($this->sorts as $sort) {
                $builder->sortBy($sort['field'], $sort['direction']);
            }

            if ($this->highlightFields !== []) {
                $builder->highlight(...$this->highlightFields);
            }

            // Fetch more per index for re-ranking
            $builder->page(1, perPage: $this->perPage * 2);

            $result = $builder->get();
            $totalOverall += $result->total;
            $totalTook += $result->took;

            // Tag hits with their source index
            foreach ($result->hits as $hit) {
                $allHits[] = new SearchHit(
                    id: $hit->id,
                    score: $hit->score,
                    document: array_merge($hit->document, ['_index' => $indexName]),
                    highlights: $hit->highlights,
                    distance: $hit->distance,
                );
            }
        }

        // Re-rank by score descending
        usort($allHits, static fn(SearchHit $a, SearchHit $b): int => $b->score <=> $a->score);

        // Paginate the merged results
        $offset = ($this->page - 1) * $this->perPage;
        $paginatedHits = array_slice($allHits, $offset, $this->perPage);

        return new SearchResult(
            hits: $paginatedHits,
            total: $totalOverall,
            page: $this->page,
            perPage: $this->perPage,
            took: $totalTook,
            meta: ['engine' => 'multi_index', 'indexes' => $this->indexNames],
        );
    }
}
