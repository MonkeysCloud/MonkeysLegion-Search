<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Configure how an entity property is indexed in the search engine.
 *
 * Controls searchability, filterability, sorting, faceting,
 * relevance weight, and custom analyzer assignment per field.
 *
 * ```php
 * #[Field(type: 'string', length: 255)]
 * #[SearchField(searchable: true, filterable: true, weight: 3)]
 * public string $title;
 *
 * #[Field(type: 'text')]
 * #[SearchField(searchable: true, weight: 1)]
 * public string $body;
 *
 * #[Field(type: 'decimal', precision: 10, scale: 2)]
 * #[SearchField(filterable: true, sortable: true)]
 * public string $price;
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class SearchField
{
    /**
     * @param bool        $searchable Whether this field is included in full-text search.
     * @param bool        $filterable Whether this field can be used in filter expressions.
     * @param bool        $sortable   Whether this field can be used for sorting results.
     * @param bool        $facetable  Whether this field generates facet counts.
     * @param int         $weight     Relevance weight multiplier (higher = more important).
     * @param string|null $analyzer   Custom analyzer name (engine-specific).
     * @param string|null $as         Custom field name in the search index.
     */
    public function __construct(
        public readonly bool $searchable = true,
        public readonly bool $filterable = false,
        public readonly bool $sortable = false,
        public readonly bool $facetable = false,
        public readonly int $weight = 1,
        public readonly ?string $analyzer = null,
        public readonly ?string $as = null,
    ) {}
}
