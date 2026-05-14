<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Middleware;

use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Dto\SearchResult;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Collects search analytics: popular queries, zero-result queries, average timing.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class AnalyticsMiddleware implements SearchMiddlewareInterface
{
    /** @var list<array{term: string, index: string, total: int, took: float, timestamp: int}> */
    private array $log = [];

    /** @var array<string, int> */
    private array $termCounts = [];

    /** @var list<string> */
    private array $zeroResultTerms = [];

    public function handle(SearchQuery $query, callable $next): SearchResult
    {
        $result = $next($query);

        $entry = [
            'term'      => $query->term,
            'index'     => $query->indexName,
            'total'     => $result->total,
            'took'      => $result->took,
            'timestamp' => time(),
        ];

        $this->log[] = $entry;

        if ($query->term !== '') {
            $normalized = mb_strtolower(trim($query->term));
            $this->termCounts[$normalized] = ($this->termCounts[$normalized] ?? 0) + 1;

            if ($result->total === 0) {
                $this->zeroResultTerms[] = $normalized;
            }
        }

        return $result;
    }

    /**
     * Get the most popular search terms.
     *
     * @param int $limit Max terms to return.
     *
     * @return array<string, int> Term => count.
     */
    public function popularTerms(int $limit = 20): array
    {
        arsort($this->termCounts);
        return array_slice($this->termCounts, 0, $limit, true);
    }

    /**
     * Get terms that returned zero results.
     *
     * @return list<string>
     */
    public function zeroResultTerms(): array
    {
        return array_values(array_unique($this->zeroResultTerms));
    }

    /**
     * Get the full analytics log.
     *
     * @return list<array{term: string, index: string, total: int, took: float, timestamp: int}>
     */
    public function log(): array
    {
        return $this->log;
    }

    /**
     * Get average query time in ms.
     */
    public function averageQueryTime(): float
    {
        if ($this->log === []) {
            return 0.0;
        }

        $total = array_sum(array_column($this->log, 'took'));
        return $total / count($this->log);
    }

    /**
     * Clear all collected analytics data.
     */
    public function reset(): void
    {
        $this->log = [];
        $this->termCounts = [];
        $this->zeroResultTerms = [];
    }
}
