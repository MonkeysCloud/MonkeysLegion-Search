<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Query;

use Generator;
use IteratorAggregate;
use MonkeysLegion\Search\Contracts\SearchEngineInterface;
use MonkeysLegion\Search\Dto\SearchHit;
use MonkeysLegion\Search\Dto\SearchQuery;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Memory-efficient lazy iterator over large search result sets.
 *
 * Fetches results in configurable chunks using paginated queries,
 * yielding one SearchHit at a time. Suitable for processing
 * millions of documents without loading all into memory.
 *
 * ```php
 * foreach ($search->index('logs')->query('error')->cursor(100) as $hit) {
 *     process($hit);
 * }
 * ```
 *
 * @template T
 * @implements IteratorAggregate<int, SearchHit>
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class LazyResults implements IteratorAggregate
{
    private int $totalProcessed = 0;
    private int $totalAvailable = 0;

    public function __construct(
        private readonly SearchEngineInterface $engine,
        private readonly SearchQuery $baseQuery,
        private readonly int $chunkSize = 100,
    ) {}

    /**
     * Total documents yielded so far.
     */
    public function totalProcessed(): int
    {
        return $this->totalProcessed;
    }

    /**
     * Total documents available (known after first fetch).
     */
    public function totalAvailable(): int
    {
        return $this->totalAvailable;
    }

    /**
     * Iterate lazily over all matching search hits.
     *
     * @return Generator<int, SearchHit>
     */
    public function getIterator(): Generator
    {
        $page = 1;
        $index = 0;

        while (true) {
            $query = new SearchQuery(
                indexName: $this->baseQuery->indexName,
                term: $this->baseQuery->term,
                filters: $this->baseQuery->filters,
                facets: [],
                sorts: $this->baseQuery->sorts,
                page: $page,
                perPage: $this->chunkSize,
                highlightFields: $this->baseQuery->highlightFields,
                selectFields: $this->baseQuery->selectFields,
                vector: $this->baseQuery->vector,
                vectorField: $this->baseQuery->vectorField,
                hybridWeight: $this->baseQuery->hybridWeight,
                extra: $this->baseQuery->extra,
                geoLat: $this->baseQuery->geoLat,
                geoLng: $this->baseQuery->geoLng,
                geoRadius: $this->baseQuery->geoRadius,
                geoField: $this->baseQuery->geoField,
                geoSort: $this->baseQuery->geoSort,
            );

            $result = $this->engine->search($query);
            $this->totalAvailable = $result->total;

            if ($result->hits === []) {
                break;
            }

            foreach ($result->hits as $hit) {
                $this->totalProcessed++;
                yield $index++ => $hit;
            }

            // Finished when no more pages
            if (!$result->hasMore) {
                break;
            }

            $page++;
        }
    }
}
