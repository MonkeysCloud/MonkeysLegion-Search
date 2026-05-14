<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Index;

use MonkeysLegion\Search\Attributes\Searchable;
use MonkeysLegion\Search\Attributes\SearchField;
use MonkeysLegion\Search\Attributes\SearchIndex;
use MonkeysLegion\Search\Dto\IndexConfig;
use MonkeysLegion\Search\Dto\IndexFieldConfig;
use MonkeysLegion\Search\Exceptions\IndexException;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Reads `#[Searchable]`, `#[SearchField]`, and `#[SearchIndex]`
 * attributes from entity classes and builds IndexConfig DTOs.
 *
 * Works with the Entity metadata system to map database field
 * types to search engine field types via FieldMapper.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class IndexSyncer
{
    /**
     * Build an IndexConfig from an entity class's attributes.
     *
     * @param class-string $entityClass Fully-qualified entity class.
     *
     * @return IndexConfig Resolved index configuration.
     *
     * @throws IndexException If the class is not marked #[Searchable].
     */
    public function buildConfig(string $entityClass): IndexConfig
    {
        $ref = new ReflectionClass($entityClass);

        // Resolve #[Searchable]
        $searchableAttrs = $ref->getAttributes(Searchable::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($searchableAttrs === []) {
            throw new IndexException(
                "Entity {$entityClass} is not marked with #[Searchable]",
            );
        }

        /** @var Searchable $searchable */
        $searchable = $searchableAttrs[0]->newInstance();

        // Determine index name: explicit > table name > class short name
        $indexName = $searchable->index ?? $this->deriveIndexName($ref);

        // Resolve #[SearchIndex] settings
        $indexSettings = $this->resolveIndexSettings($ref);

        // Resolve #[SearchField] on properties
        $fields = $this->resolveFields($ref);

        return new IndexConfig(
            name: $indexName,
            primaryKey: $searchable->idField,
            fields: $fields,
            stopWords: $indexSettings['stopWords'],
            synonyms: $indexSettings['synonyms'],
            rankingRules: $indexSettings['rankingRules'],
            tokenSeparators: $indexSettings['tokenSeparators'],
            maxTotalHits: $indexSettings['maxTotalHits'],
            extra: $indexSettings['extra'],
        );
    }

    /**
     * Check if a class has the #[Searchable] attribute.
     *
     * @param class-string $entityClass
     */
    public function isSearchable(string $entityClass): bool
    {
        $ref = new ReflectionClass($entityClass);
        return $ref->getAttributes(Searchable::class) !== [];
    }

    /**
     * Resolve the #[Searchable] attribute from an entity class.
     *
     * @param class-string $entityClass
     */
    public function getSearchable(string $entityClass): ?Searchable
    {
        $ref = new ReflectionClass($entityClass);
        $attrs = $ref->getAttributes(Searchable::class);

        if ($attrs === []) {
            return null;
        }

        /** @var Searchable */
        return $attrs[0]->newInstance();
    }

    // ── Internal ───────────────────────────────────────────────

    /**
     * Derive an index name from the class using snake_case pluralisation.
     *
     * @param ReflectionClass<object> $ref
     */
    private function deriveIndexName(ReflectionClass $ref): string
    {
        $shortName = $ref->getShortName();

        // PascalCase → snake_case
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));

        // Naive pluralisation
        if (!str_ends_with($snake, 's')) {
            $snake .= 's';
        }

        return $snake;
    }

    /**
     * Resolve #[SearchIndex] class-level settings.
     *
     * @param ReflectionClass<object> $ref
     *
     * @return array{
     *     stopWords: list<string>,
     *     synonyms: array<string, list<string>>,
     *     rankingRules: list<string>,
     *     tokenSeparators: list<string>,
     *     maxTotalHits: int|null,
     *     extra: array<string, mixed>,
     * }
     */
    private function resolveIndexSettings(ReflectionClass $ref): array
    {
        $attrs = $ref->getAttributes(SearchIndex::class);

        if ($attrs === []) {
            return [
                'stopWords'       => [],
                'synonyms'        => [],
                'rankingRules'    => [],
                'tokenSeparators' => [],
                'maxTotalHits'    => null,
                'extra'           => [],
            ];
        }

        /** @var SearchIndex $settings */
        $settings = $attrs[0]->newInstance();

        return [
            'stopWords'       => $settings->stopWords,
            'synonyms'        => $settings->synonyms,
            'rankingRules'    => $settings->rankingRules,
            'tokenSeparators' => $settings->tokenSeparators,
            'maxTotalHits'    => $settings->maxTotalHits,
            'extra'           => $settings->extra,
        ];
    }

    /**
     * Resolve #[SearchField] from all properties.
     *
     * @param ReflectionClass<object> $ref
     *
     * @return list<IndexFieldConfig>
     */
    private function resolveFields(ReflectionClass $ref): array
    {
        $fields = [];

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $sfAttrs = $prop->getAttributes(SearchField::class);

            if ($sfAttrs === []) {
                continue;
            }

            /** @var SearchField $sf */
            $sf = $sfAttrs[0]->newInstance();

            // Determine field type from Entity\Attributes\Field if present
            $fieldType = $this->resolveFieldType($prop);

            $fields[] = new IndexFieldConfig(
                name: $sf->as ?? $prop->getName(),
                type: $fieldType,
                searchable: $sf->searchable,
                filterable: $sf->filterable,
                sortable: $sf->sortable,
                facetable: $sf->facetable,
                weight: $sf->weight,
                analyzer: $sf->analyzer,
            );
        }

        return $fields;
    }

    /**
     * Resolve the search FieldType from the Entity #[Field] attribute.
     */
    private function resolveFieldType(ReflectionProperty $prop): \MonkeysLegion\Search\Enum\FieldType
    {
        // Try to read Entity\Attributes\Field
        $fieldAttrs = $prop->getAttributes(
            \MonkeysLegion\Entity\Attributes\Field::class,
            ReflectionAttribute::IS_INSTANCEOF,
        );

        if ($fieldAttrs !== []) {
            /** @var \MonkeysLegion\Entity\Attributes\Field $field */
            $field = $fieldAttrs[0]->newInstance();
            return FieldMapper::toSearchType($field->type);
        }

        // Fallback: infer from PHP type
        $type = $prop->getType();

        if ($type instanceof \ReflectionNamedType) {
            return match ($type->getName()) {
                'string'             => \MonkeysLegion\Search\Enum\FieldType::Text,
                'int'                => \MonkeysLegion\Search\Enum\FieldType::Integer,
                'float'              => \MonkeysLegion\Search\Enum\FieldType::Float,
                'bool'               => \MonkeysLegion\Search\Enum\FieldType::Boolean,
                'DateTimeImmutable',
                'DateTime'           => \MonkeysLegion\Search\Enum\FieldType::Date,
                'array'              => \MonkeysLegion\Search\Enum\FieldType::Json,
                default              => \MonkeysLegion\Search\Enum\FieldType::Text,
            };
        }

        return \MonkeysLegion\Search\Enum\FieldType::Text;
    }
}
