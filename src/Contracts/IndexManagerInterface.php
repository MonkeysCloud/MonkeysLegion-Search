<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Contracts;

use MonkeysLegion\Search\Dto\IndexConfig;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Contract for index lifecycle management.
 *
 * Coordinates schema syncing from Entity metadata,
 * index creation/deletion, and settings updates
 * across all configured search engines.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface IndexManagerInterface
{
    /**
     * Sync an entity's search index schema from its attributes.
     *
     * @param class-string $entityClass Fully-qualified entity class name.
     */
    public function syncFromEntity(string $entityClass): void;

    /**
     * Sync all registered searchable entities.
     */
    public function syncAll(): void;

    /**
     * Create an index from a configuration DTO.
     *
     * @param IndexConfig $config Index definition.
     */
    public function create(IndexConfig $config): void;

    /**
     * Drop an index by name.
     *
     * @param string $indexName Index to drop.
     */
    public function drop(string $indexName): void;

    /**
     * Check if an index exists.
     *
     * @param string $indexName Index to check.
     */
    public function exists(string $indexName): bool;

    /**
     * Get configuration for an entity's search index.
     *
     * @param class-string $entityClass Entity class.
     *
     * @return IndexConfig Resolved index config.
     */
    public function configFor(string $entityClass): IndexConfig;

    /**
     * List all managed index names.
     *
     * @return list<string>
     */
    public function listIndexes(): array;
}
