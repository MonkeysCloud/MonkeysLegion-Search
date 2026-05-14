<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Middleware;

use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Dto\SearchResult;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Contract for search middleware.
 *
 * Middleware intercepts search queries before/after engine execution,
 * enabling logging, analytics, caching, rate limiting, etc.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface SearchMiddlewareInterface
{
    /**
     * Handle a search query.
     *
     * @param SearchQuery                        $query The search query.
     * @param callable(SearchQuery): SearchResult $next  Next middleware or engine.
     *
     * @return SearchResult
     */
    public function handle(SearchQuery $query, callable $next): SearchResult;
}
