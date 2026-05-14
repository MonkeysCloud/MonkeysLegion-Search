<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Traits;

use MonkeysLegion\Search\Attributes\Searchable;
use MonkeysLegion\Search\Attributes\SearchField;
use MonkeysLegion\Search\Query\Builder;
use MonkeysLegion\Search\SearchManager;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Trait for searchable entities — provides Scout-style API.
 *
 * Attach to any entity marked with `#[Searchable]` to gain:
 *  • `toSearchableArray()` — control indexed data
 *  • `shouldBeSearchable()` — conditional indexing
 *  • `getSearchKey()` / `getSearchIndex()` — identity
 *  • `searchable()` / `unsearchable()` — instance-level sync
 *  • `static search()` — fluent search on the entity's index
 *  • `static makeSearchable()` / `makeUnsearchable()` — bulk ops
 *
 * ```php
 * #[Entity(table: 'products')]
 * #[Searchable]
 * class Product
 * {
 *     use SearchableTrait;
 *
 *     public function toSearchableArray(): array
 *     {
 *         return ['id' => $this->id, 'name' => $this->name];
 *     }
 * }
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
trait SearchableTrait
{
    /**
     * Cached SearchManager instance — set via setSearchManager().
     */
    private static ?SearchManager $searchManager = null;

    /**
     * Inject the SearchManager (called by SearchProvider at boot).
     */
    public static function setSearchManager(SearchManager $manager): void
    {
        self::$searchManager = $manager;
    }

    /**
     * Get the data array that should be indexed.
     *
     * Override this to control exactly which fields are sent
     * to the search engine, including flattened relationships.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $data = [];
        $ref = new ReflectionClass($this);

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $sfAttrs = $prop->getAttributes(SearchField::class);
            if ($sfAttrs !== []) {
                /** @var SearchField $sf */
                $sf = $sfAttrs[0]->newInstance();
                $fieldName = $sf->as ?? $prop->getName();
                $data[$fieldName] = $prop->getValue($this);
            }
        }

        // Always include the search key
        $data[$this->getSearchKeyName()] = $this->getSearchKey();

        return $data;
    }

    /**
     * Determine if the entity should be searchable.
     *
     * Override to implement conditional indexing — e.g. only
     * index published entities, skip drafts, etc.
     */
    public function shouldBeSearchable(): bool
    {
        return true;
    }

    /**
     * Get the document ID for the search index.
     */
    public function getSearchKey(): string
    {
        $keyName = $this->getSearchKeyName();

        if (property_exists($this, $keyName)) {
            return (string) $this->{$keyName};
        }

        return '';
    }

    /**
     * Get the property name used as the search key.
     */
    public function getSearchKeyName(): string
    {
        $ref = new ReflectionClass($this);
        $attrs = $ref->getAttributes(Searchable::class);

        if ($attrs !== []) {
            /** @var Searchable $searchable */
            $searchable = $attrs[0]->newInstance();
            return $searchable->idField;
        }

        return 'id';
    }

    /**
     * Get the search index name for this entity.
     */
    public function getSearchIndex(): string
    {
        $ref = new ReflectionClass($this);
        $attrs = $ref->getAttributes(Searchable::class);

        if ($attrs !== []) {
            /** @var Searchable $searchable */
            $searchable = $attrs[0]->newInstance();

            if ($searchable->index !== null) {
                return $searchable->index;
            }
        }

        // Fallback: snake_case + pluralize class name
        $name = $ref->getShortName();
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));

        return str_ends_with($snake, 's') ? $snake : $snake . 's';
    }

    /**
     * Get the engine connection name for this entity.
     */
    public function getSearchEngine(): string
    {
        $ref = new ReflectionClass($this);
        $attrs = $ref->getAttributes(Searchable::class);

        if ($attrs !== []) {
            /** @var Searchable $searchable */
            $searchable = $attrs[0]->newInstance();
            return $searchable->engine;
        }

        return 'default';
    }

    /**
     * Index this entity instance in the search engine.
     */
    public function searchable(): void
    {
        $manager = self::resolveSearchManager();

        if (!$this->shouldBeSearchable()) {
            $this->unsearchable();
            return;
        }

        $manager->indexDocument(
            $this->getSearchIndex(),
            $this->getSearchKey(),
            $this->toSearchableArray(),
            $this->getSearchEngine(),
        );
    }

    /**
     * Remove this entity instance from the search engine.
     */
    public function unsearchable(): void
    {
        $manager = self::resolveSearchManager();

        $manager->deleteDocument(
            $this->getSearchIndex(),
            $this->getSearchKey(),
            $this->getSearchEngine(),
        );
    }

    /**
     * Create a fluent search builder for this entity's index.
     */
    public static function search(string $term = ''): Builder
    {
        $manager = self::resolveSearchManager();

        // Need an instance to get index/engine — use reflection
        $ref = new ReflectionClass(static::class);
        $attrs = $ref->getAttributes(Searchable::class);

        $index = null;
        $engine = 'default';
        if ($attrs !== []) {
            /** @var Searchable $searchable */
            $searchable = $attrs[0]->newInstance();
            $index = $searchable->index;
            $engine = $searchable->engine;
        }

        if ($index === null) {
            $name = $ref->getShortName();
            $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
            $index = str_ends_with($snake, 's') ? $snake : $snake . 's';
        }

        $builder = $manager->index($index, $engine);

        if ($term !== '') {
            $builder->query($term);
        }

        return $builder;
    }

    /**
     * Bulk-index multiple entity instances.
     *
     * @param iterable<object> $entities
     */
    public static function makeSearchable(iterable $entities): int
    {
        $manager = self::resolveSearchManager();
        $grouped = self::groupEntitiesForBulk($entities);
        $total = 0;

        foreach ($grouped as $key => $group) {
            [$index, $engine] = explode('|', $key);
            $documents = [];
            foreach ($group as $entity) {
                if (method_exists($entity, 'shouldBeSearchable') && !$entity->shouldBeSearchable()) {
                    continue;
                }
                $documents[] = $entity->toSearchableArray();
            }
            if ($documents !== []) {
                $total += $manager->engine($engine)->bulkIndex($index, $documents);
            }
        }

        return $total;
    }

    /**
     * Bulk-remove multiple entity instances from the index.
     *
     * @param iterable<object> $entities
     */
    public static function makeUnsearchable(iterable $entities): int
    {
        $manager = self::resolveSearchManager();
        $grouped = self::groupEntitiesForBulk($entities);
        $total = 0;

        foreach ($grouped as $key => $group) {
            [$index, $engine] = explode('|', $key);
            $ids = array_map(
                static fn(object $e): string => $e->getSearchKey(),
                $group,
            );
            if ($ids !== []) {
                $total += $manager->engine($engine)->bulkDelete($index, $ids);
            }
        }

        return $total;
    }

    // ── Internal ───────────────────────────────────────────────

    /**
     * Resolve the SearchManager instance.
     */
    private static function resolveSearchManager(): SearchManager
    {
        if (self::$searchManager === null) {
            throw new \RuntimeException(
                'SearchManager not set. Call ' . static::class . '::setSearchManager() or register SearchProvider.',
            );
        }

        return self::$searchManager;
    }

    /**
     * Group entities by index|engine for bulk operations.
     *
     * @param iterable<object> $entities
     *
     * @return array<string, list<object>>
     */
    private static function groupEntitiesForBulk(iterable $entities): array
    {
        $grouped = [];
        foreach ($entities as $entity) {
            $key = $entity->getSearchIndex() . '|' . $entity->getSearchEngine();
            $grouped[$key][] = $entity;
        }
        return $grouped;
    }
}
