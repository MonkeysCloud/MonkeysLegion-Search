<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Mark an entity class as searchable.
 *
 * When applied, the IndexSyncer reads this attribute alongside
 * `#[SearchField]` annotations to build the search index schema
 * and optionally auto-sync documents on entity persist/delete.
 *
 * ```php
 * #[Entity(table: 'products')]
 * #[Searchable(index: 'products', engine: 'meilisearch')]
 * class Product
 * {
 *     #[SearchField(searchable: true, filterable: true, sortable: true)]
 *     public string $name;
 * }
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Searchable
{
    /**
     * @param string|null $index    Custom index name. Defaults to entity table name.
     * @param string      $engine   Engine connection name from config.
     * @param bool        $autoSync Auto-index on entity persist/update/delete events.
     * @param bool        $queue    Queue indexing operations via monkeyslegion-queue.
     * @param string      $idField  Entity property to use as the document ID.
     */
    public function __construct(
        public readonly ?string $index = null,
        public readonly string $engine = 'default',
        public readonly bool $autoSync = true,
        public readonly bool $queue = false,
        public readonly string $idField = 'id',
    ) {}
}
