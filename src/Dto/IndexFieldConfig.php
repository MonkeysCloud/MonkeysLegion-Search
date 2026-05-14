<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Dto;

use MonkeysLegion\Search\Enum\FieldType;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Immutable DTO representing a single field in an index schema.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class IndexFieldConfig
{
    /**
     * @param string      $name       Field name in the search index.
     * @param FieldType   $type       Data type.
     * @param bool        $searchable Include in full-text search.
     * @param bool        $filterable Allow filtering on this field.
     * @param bool        $sortable   Allow sorting on this field.
     * @param bool        $facetable  Generate facet counts.
     * @param int         $weight     Relevance weight.
     * @param string|null $analyzer   Custom analyzer name.
     */
    public function __construct(
        public string $name,
        public FieldType $type = FieldType::Text,
        public bool $searchable = true,
        public bool $filterable = false,
        public bool $sortable = false,
        public bool $facetable = false,
        public int $weight = 1,
        public ?string $analyzer = null,
    ) {}
}
