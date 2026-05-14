<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Index;

use MonkeysLegion\Search\Contracts\IndexManagerInterface;
use MonkeysLegion\Search\Contracts\SearchEngineInterface;
use MonkeysLegion\Search\Dto\IndexConfig;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Manages index lifecycle across search engines.
 *
 * Coordinates IndexSyncer (attribute reading) with the engine
 * adapter (index creation/settings update) and provides a
 * registry of all managed searchable entities.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class IndexManager implements IndexManagerInterface
{
    /** @var array<class-string, IndexConfig> Cached configs keyed by entity class. */
    private array $configCache = [];

    /** @var list<class-string> Registered searchable entity classes. */
    private array $entityClasses = [];

    public function __construct(
        private readonly SearchEngineInterface $engine,
        private readonly IndexSyncer $syncer,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Register a searchable entity class.
     *
     * @param class-string $entityClass
     */
    public function register(string $entityClass): void
    {
        if (!in_array($entityClass, $this->entityClasses, true)) {
            $this->entityClasses[] = $entityClass;
        }
    }

    /**
     * Sync an entity's search index schema from its attributes.
     *
     * @param class-string $entityClass
     */
    public function syncFromEntity(string $entityClass): void
    {
        $config = $this->configFor($entityClass);

        if ($this->engine->indexExists($config->name)) {
            $this->updateIndexSettings($config);
        } else {
            $this->engine->createIndex($config);
        }

        $this->logger->info("Search index synced: {$config->name}", [
            'entity' => $entityClass,
            'fields' => count($config->fields),
        ]);
    }

    /**
     * Sync all registered searchable entities.
     */
    public function syncAll(): void
    {
        foreach ($this->entityClasses as $entityClass) {
            $this->syncFromEntity($entityClass);
        }
    }

    /**
     * Create an index from a configuration DTO.
     */
    public function create(IndexConfig $config): void
    {
        $this->engine->createIndex($config);

        $this->logger->info("Search index created: {$config->name}");
    }

    /**
     * Drop an index by name.
     */
    public function drop(string $indexName): void
    {
        $this->engine->deleteIndex($indexName);

        $this->logger->info("Search index dropped: {$indexName}");
    }

    /**
     * Check if an index exists.
     */
    public function exists(string $indexName): bool
    {
        return $this->engine->indexExists($indexName);
    }

    /**
     * Get configuration for an entity's search index.
     *
     * @param class-string $entityClass
     */
    public function configFor(string $entityClass): IndexConfig
    {
        if (!isset($this->configCache[$entityClass])) {
            $this->configCache[$entityClass] = $this->syncer->buildConfig($entityClass);
        }

        return $this->configCache[$entityClass];
    }

    /**
     * List all managed index names.
     *
     * @return list<string>
     */
    public function listIndexes(): array
    {
        return array_values(array_map(
            fn(string $class): string => $this->configFor($class)->name,
            $this->entityClasses,
        ));
    }

    // ── Internal ───────────────────────────────────────────────

    /**
     * Update engine-specific settings for an existing index.
     */
    private function updateIndexSettings(IndexConfig $config): void
    {
        $settings = [];

        $searchable = $config->searchableFields();
        if ($searchable !== []) {
            $settings['searchableAttributes'] = $searchable;
        }

        $filterable = $config->filterableFields();
        if ($filterable !== []) {
            $settings['filterableAttributes'] = $filterable;
        }

        $sortable = $config->sortableFields();
        if ($sortable !== []) {
            $settings['sortableAttributes'] = $sortable;
        }

        if ($config->stopWords !== []) {
            $settings['stopWords'] = $config->stopWords;
        }

        if ($config->synonyms !== []) {
            $settings['synonyms'] = $config->synonyms;
        }

        if ($settings !== []) {
            $this->engine->updateSettings($config->name, $settings);
        }
    }
}
