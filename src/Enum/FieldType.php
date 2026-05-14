<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Enum;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Search field data types used in index schema definitions.
 * Maps Entity field types to search-engine-native types.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
enum FieldType: string
{
    case String  = 'string';
    case Text    = 'text';
    case Integer = 'integer';
    case Float   = 'float';
    case Boolean = 'boolean';
    case Date    = 'date';
    case Geo     = 'geo';
    case Json    = 'json';
    case Vector  = 'vector';

    /**
     * Whether this type supports full-text search (tokenized).
     */
    public function isFullText(): bool
    {
        return match ($this) {
            self::Text, self::String => true,
            default => false,
        };
    }

    /**
     * Whether this type supports numeric range filtering.
     */
    public function isNumeric(): bool
    {
        return match ($this) {
            self::Integer, self::Float => true,
            default => false,
        };
    }
}
