<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Dto;

use MonkeysLegion\Search\Enum\FieldType;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Immutable DTO describing a search index schema.
 *
 * Built by IndexSyncer from entity attributes or created
 * manually for custom indexes.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class IndexConfig
{
    /**
     * @param string                     $name            Index name.
     * @param string                     $primaryKey      Document ID field.
     * @param list<IndexFieldConfig>     $fields          Field definitions.
     * @param list<string>               $stopWords       Words to ignore.
     * @param array<string, list<string>> $synonyms       Synonym groups.
     * @param list<string>               $rankingRules    Engine-specific ranking rules.
     * @param list<string>               $tokenSeparators Custom token separators.
     * @param int|null                   $maxTotalHits    Max retrievable hits.
     * @param array<string, mixed>       $extra           Engine-specific raw settings.
     */
    public function __construct(
        public string $name,
        public string $primaryKey = 'id',
        public array $fields = [],
        public array $stopWords = [],
        public array $synonyms = [],
        public array $rankingRules = [],
        public array $tokenSeparators = [],
        public ?int $maxTotalHits = null,
        public array $extra = [],
    ) {}

    /**
     * Get field names marked as searchable.
     *
     * @return list<string>
     */
    public function searchableFields(): array
    {
        return array_values(array_map(
            static fn(IndexFieldConfig $f): string => $f->name,
            array_filter(
                $this->fields,
                static fn(IndexFieldConfig $f): bool => $f->searchable,
            ),
        ));
    }

    /**
     * Get field names marked as filterable.
     *
     * @return list<string>
     */
    public function filterableFields(): array
    {
        return array_values(array_map(
            static fn(IndexFieldConfig $f): string => $f->name,
            array_filter(
                $this->fields,
                static fn(IndexFieldConfig $f): bool => $f->filterable,
            ),
        ));
    }

    /**
     * Get field names marked as sortable.
     *
     * @return list<string>
     */
    public function sortableFields(): array
    {
        return array_values(array_map(
            static fn(IndexFieldConfig $f): string => $f->name,
            array_filter(
                $this->fields,
                static fn(IndexFieldConfig $f): bool => $f->sortable,
            ),
        ));
    }

    /**
     * Get field names marked as facetable.
     *
     * @return list<string>
     */
    public function facetableFields(): array
    {
        return array_values(array_map(
            static fn(IndexFieldConfig $f): string => $f->name,
            array_filter(
                $this->fields,
                static fn(IndexFieldConfig $f): bool => $f->facetable,
            ),
        ));
    }
}
