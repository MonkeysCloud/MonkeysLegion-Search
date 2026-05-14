<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Observers;

use MonkeysLegion\Entity\Attributes\Subscribe;
use MonkeysLegion\Entity\Support\EntityEvent;
use MonkeysLegion\Search\Attributes\Searchable;
use MonkeysLegion\Search\SearchManager;

use ReflectionClass;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Global entity subscriber for search auto-sync.
 *
 * Uses `#[Subscribe]` (empty entities = ALL entities) to listen
 * for lifecycle events on every entity, then checks if the entity
 * has `#[Searchable(autoSync: true)]` before indexing/deleting.
 *
 * This is the "zero-config" approach — entities only need
 * `#[Searchable]` and `SearchableTrait`, no `#[ObservedBy]` needed.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Subscribe]
final class SearchSubscriber
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
     * After entity is created — index if searchable.
     */
    public function created(object $entity, EntityEvent $event): void
    {
        $this->syncEntity($entity);
    }

    /**
     * After entity is updated — re-index or remove.
     */
    public function updated(object $entity, EntityEvent $event): void
    {
        $this->syncEntity($entity);
    }

    /**
     * After entity is saved — sync.
     */
    public function saved(object $entity, EntityEvent $event): void
    {
        $this->syncEntity($entity);
    }

    /**
     * After entity is deleted — remove from index.
     */
    public function deleted(object $entity, EntityEvent $event): void
    {
        $this->removeEntity($entity);
    }

    /**
     * After soft-deleted entity is restored — re-index.
     */
    public function restored(object $entity, EntityEvent $event): void
    {
        $this->syncEntity($entity);
    }

    // ── Internal ───────────────────────────────────────────────

    private function syncEntity(object $entity): void
    {
        if (!$this->shouldAutoSync($entity)) {
            return;
        }

        if (method_exists($entity, 'shouldBeSearchable') && !$entity->shouldBeSearchable()) {
            $this->removeEntity($entity);
            return;
        }

        if (method_exists($entity, 'searchable')) {
            $entity->searchable();
        }
    }

    private function removeEntity(object $entity): void
    {
        if (!$this->shouldAutoSync($entity)) {
            return;
        }

        if (method_exists($entity, 'unsearchable')) {
            $entity->unsearchable();
        }
    }

    /**
     * Check if entity has #[Searchable(autoSync: true)].
     */
    private function shouldAutoSync(object $entity): bool
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
}
