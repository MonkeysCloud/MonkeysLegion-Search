<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Dto;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Immutable DTO for an aggregation request.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class Aggregation
{
    /**
     * @param string               $name     Aggregation name (used as key in results).
     * @param string               $type     Type: sum, avg, min, max, cardinality, histogram, date_histogram, terms.
     * @param string               $field    Field to aggregate on.
     * @param array<string, mixed> $options  Extra options (interval, size, etc.).
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $field,
        public array $options = [],
    ) {}
}
