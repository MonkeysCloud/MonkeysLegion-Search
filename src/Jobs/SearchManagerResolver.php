<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Jobs;

use MonkeysLegion\Search\SearchManager;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Resolves the SearchManager singleton for queue job execution.
 *
 * Jobs run in a worker process that may not have the same DI container.
 * This resolver provides a static registry for the SearchManager instance.
 *
 * @internal
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class SearchManagerResolver
{
    private static ?SearchManager $manager = null;

    /**
     * Set the SearchManager instance (called by SearchProvider).
     */
    public static function set(SearchManager $manager): void
    {
        self::$manager = $manager;
    }

    /**
     * Resolve the SearchManager instance.
     *
     * @throws \RuntimeException If not registered.
     */
    public static function resolve(): SearchManager
    {
        if (self::$manager === null) {
            throw new \RuntimeException(
                'SearchManager not registered for queue jobs. Ensure SearchProvider is booted.',
            );
        }

        return self::$manager;
    }

    /**
     * Clear the registered instance (for testing).
     */
    public static function clear(): void
    {
        self::$manager = null;
    }
}
