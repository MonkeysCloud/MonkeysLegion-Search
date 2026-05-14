<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Index;

use MonkeysLegion\Search\Enum\FieldType;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Maps Entity `#[Field]` type values to search-engine FieldType enums.
 *
 * Used by IndexSyncer when building IndexConfig from entity metadata.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class FieldMapper
{
    /**
     * Map an entity field type string to a search FieldType.
     *
     * @param string $entityFieldType The `type` value from `#[Field(type: ...)]`.
     */
    public static function toSearchType(string $entityFieldType): FieldType
    {
        return match ($entityFieldType) {
            'string', 'char', 'uuid', 'ipAddress', 'macAddress' => FieldType::String,
            'text', 'mediumText', 'longText'                    => FieldType::Text,
            'integer', 'int', 'tinyInt', 'smallInt',
            'bigInt', 'unsignedBigInt', 'year'                  => FieldType::Integer,
            'decimal', 'float'                                  => FieldType::Float,
            'boolean', 'bool'                                   => FieldType::Boolean,
            'date', 'time', 'datetime', 'datetimetz',
            'timestamp', 'timestamptz'                          => FieldType::Date,
            'json', 'simple_json', 'array', 'simple_array'     => FieldType::Json,
            'point', 'geometry'                                 => FieldType::Geo,
            'vector'                                            => FieldType::Vector,
            default                                             => FieldType::Text,
        };
    }
}
