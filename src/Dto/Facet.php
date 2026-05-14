<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Dto;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Immutable DTO representing a facet distribution for a field.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class Facet
{
    /**
     * @param string             $field  Faceted field name.
     * @param array<string, int> $values Value => document count.
     */
    public function __construct(
        public readonly string $field,
        public readonly array $values,
    ) {}

    /**
     * Total number of distinct facet values.
     */
    public int $count {
        get => count($this->values);
    }
}
