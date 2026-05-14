<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Middleware;

use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Dto\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Logs all search queries with term, filters, timing, and result count.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class LoggingMiddleware implements SearchMiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(SearchQuery $query, callable $next): SearchResult
    {
        $start = hrtime(true);

        $result = $next($query);

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        $this->logger->info('Search query executed', [
            'index'    => $query->indexName,
            'term'     => $query->term,
            'filters'  => count($query->filters),
            'page'     => $query->page,
            'perPage'  => $query->perPage,
            'total'    => $result->total,
            'hits'     => $result->count,
            'took_ms'  => round($durationMs, 2),
            'engine'   => $result->meta['engine'] ?? 'unknown',
        ]);

        return $result;
    }
}
