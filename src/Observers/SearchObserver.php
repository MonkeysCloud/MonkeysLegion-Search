<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Observers;

use MonkeysLegion\Entity\Observers\EntityObserver;
use MonkeysLegion\Search\Attributes\Searchable;
use MonkeysLegion\Search\SearchManager;

use ReflectionClass;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Entity observer that auto-syncs searchable entities to the search index.
 *
 * Register on entities with `#[ObservedBy(SearchObserver::class)]`.
 * Hooks into saved/deleted/restored lifecycle events.
 *
 * Checks `shouldBeSearchable()` before indexing — if false, removes
 * the document instead (handles status transitions like publish→draft).
 *
 * ```php
 * #[Entity(table: 'products')]
 * #[Searchable(autoSync: true)]
 * #[ObservedBy(SearchObserver::class)]
 * class Product { use SearchableTrait; }
 * ```
 *
 * @template T of object
 * @extends EntityObserver<T>
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class SearchObserver extends EntityObserver
{
    private static ?SearchManager $searchManager = null;

    /**
     * Inject the SearchManager (called by SearchProvider at boot).
     */
    public static function setSearchManager(SearchManager $manager): void
    {
        self::$searchManager = $manager;
    }

    /**
     * After an entity is created — index it.
     *
     * @param T $entity
     */
    public function created(object $entity): void
    {
        $this->syncToIndex($entity);
    }

    /**
     * After an entity is updated — re-index or remove.
     *
     * @param T $entity
     */
    public function updated(object $entity): void
    {
        $this->syncToIndex($entity);
    }

    /**
     * After an entity is saved (create or update) — sync.
     *
     * @param T $entity
     */
    public function saved(object $entity): void
    {
        $this->syncToIndex($entity);
    }

    /**
     * After an entity is deleted — remove from index.
     *
     * @param T $entity
     */
    public function deleted(object $entity): void
    {
        $this->removeFromIndex($entity);
    }

    /**
     * After a soft-deleted entity is restored — re-index.
     *
     * @param T $entity
     */
    public function restored(object $entity): void
    {
        $this->syncToIndex($entity);
    }

    // ── Internal ───────────────────────────────────────────────

    /**
     * Sync the entity to the search index.
     */
    private function syncToIndex(object $entity): void
    {
        if (!$this->isSearchable($entity)) {
            return;
        }

        // Check shouldBeSearchable — if false, remove instead
        if (method_exists($entity, 'shouldBeSearchable') && !$entity->shouldBeSearchable()) {
            $this->removeFromIndex($entity);
            return;
        }

        if (method_exists($entity, 'searchable')) {
            $entity->searchable();
            return;
        }

        // Fallback: direct indexing via manager
        $manager = $this->resolveManager();
        if ($manager === null) {
            return;
        }

        $data = method_exists($entity, 'toSearchableArray')
            ? $entity->toSearchableArray()
            : $this->extractPublicProperties($entity);

        $index = method_exists($entity, 'getSearchIndex') ? $entity->getSearchIndex() : '';
        $key = method_exists($entity, 'getSearchKey') ? $entity->getSearchKey() : '';
        $engine = method_exists($entity, 'getSearchEngine') ? $entity->getSearchEngine() : null;

        if ($index !== '' && $key !== '') {
            $manager->indexDocument($index, $key, $data, $engine);
        }
    }

    /**
     * Remove the entity from the search index.
     */
    private function removeFromIndex(object $entity): void
    {
        if (!$this->isSearchable($entity)) {
            return;
        }

        if (method_exists($entity, 'unsearchable')) {
            $entity->unsearchable();
            return;
        }

        $manager = $this->resolveManager();
        if ($manager === null) {
            return;
        }

        $index = method_exists($entity, 'getSearchIndex') ? $entity->getSearchIndex() : '';
        $key = method_exists($entity, 'getSearchKey') ? $entity->getSearchKey() : '';
        $engine = method_exists($entity, 'getSearchEngine') ? $entity->getSearchEngine() : null;

        if ($index !== '' && $key !== '') {
            $manager->deleteDocument($index, $key, $engine);
        }
    }

    /**
     * Check if entity has the #[Searchable] attribute with autoSync.
     */
    private function isSearchable(object $entity): bool
    {
        $ref = new ReflectionClass($entity);
        $attrs = $ref->getAttributes(Searchable::class);

        if ($attrs === []) {
            return false;
        }

        /** @var Searchable $searchable */
        $searchable = $attrs[0]->newInstance();
        return $searchable->autoSync;
    }

    private function resolveManager(): ?SearchManager
    {
        return self::$searchManager;
    }

    /**
     * Extract all public property values as a searchable array.
     *
     * @return array<string, mixed>
     */
    private function extractPublicProperties(object $entity): array
    {
        $data = [];
        $ref = new ReflectionClass($entity);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isInitialized($entity)) {
                $data[$prop->getName()] = $prop->getValue($entity);
            }
        }

        return $data;
    }
}
