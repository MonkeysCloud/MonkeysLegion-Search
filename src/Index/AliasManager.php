<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Index;

use MonkeysLegion\Search\Support\HttpClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Manages index aliases for zero-downtime reindexing.
 *
 * Supports OpenSearch and Elasticsearch alias operations:
 *  • Create versioned indexes (products_v1, products_v2)
 *  • Atomic alias swap (products → products_v2)
 *  • Cleanup old versions
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class AliasManager
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Create a new versioned index name.
     *
     * @param string $baseName Base index name (e.g. "products").
     *
     * @return string Versioned name (e.g. "products_v1695000000").
     */
    public function createVersionedIndex(string $baseName): string
    {
        return $baseName . '_v' . time();
    }

    /**
     * Atomically swap an alias from one index to another.
     *
     * Uses the ES/OpenSearch _aliases API for atomic operations.
     *
     * @param string $alias    Alias name (e.g. "products").
     * @param string $newIndex New index to point the alias to.
     */
    public function swap(string $alias, string $newIndex): void
    {
        // Get current index pointed to by alias
        $currentIndex = $this->getCurrentIndex($alias);

        $actions = [];

        // Remove alias from current index
        if ($currentIndex !== null) {
            $actions[] = ['remove' => ['index' => $currentIndex, 'alias' => $alias]];
        }

        // Add alias to new index
        $actions[] = ['add' => ['index' => $newIndex, 'alias' => $alias]];

        $r = $this->http->post('/_aliases', ['actions' => $actions]);

        if ($r->isSuccess) {
            $this->logger->info("Alias swapped: {$alias} → {$newIndex}", [
                'previous' => $currentIndex,
            ]);
        } else {
            $this->logger->error("Alias swap failed: {$r->body}");
        }
    }

    /**
     * Cleanup old versioned indexes, keeping only the latest N.
     *
     * @param string $baseName     Base index name.
     * @param int    $keepVersions Number of versions to keep.
     */
    public function cleanup(string $baseName, int $keepVersions = 2): void
    {
        $r = $this->http->get('/_cat/indices/' . $baseName . '_v*', ['format' => 'json']);

        if (!$r->isSuccess) {
            return;
        }

        $data = $r->json();
        if (!is_array($data)) {
            return;
        }

        // Sort by creation date (index name contains timestamp)
        $indexes = [];
        foreach ($data as $idx) {
            if (isset($idx['index']) && is_string($idx['index'])) {
                $indexes[] = $idx['index'];
            }
        }

        sort($indexes);

        // Keep the latest N versions, delete the rest
        $toDelete = array_slice($indexes, 0, max(0, count($indexes) - $keepVersions));

        foreach ($toDelete as $oldIndex) {
            // Don't delete if it has an active alias
            if ($this->hasAlias($oldIndex)) {
                continue;
            }

            $this->http->delete("/{$oldIndex}");
            $this->logger->info("Cleaned up old index: {$oldIndex}");
        }
    }

    /**
     * Check if an alias exists.
     */
    public function aliasExists(string $alias): bool
    {
        $r = $this->http->get("/_alias/{$alias}");
        return $r->isSuccess;
    }

    // ── Internal ───────────────────────────────────────────────

    /**
     * Get the current index pointed to by an alias.
     */
    private function getCurrentIndex(string $alias): ?string
    {
        $r = $this->http->get("/_alias/{$alias}");

        if (!$r->isSuccess) {
            return null;
        }

        $data = $r->json();
        $indexes = array_keys($data);

        return $indexes[0] ?? null;
    }

    /**
     * Check if an index has any alias pointing to it.
     */
    private function hasAlias(string $indexName): bool
    {
        $r = $this->http->get("/{$indexName}/_alias");

        if (!$r->isSuccess) {
            return false;
        }

        $data = $r->json();
        $aliases = $data[$indexName]['aliases'] ?? [];

        return is_array($aliases) && $aliases !== [];
    }
}
