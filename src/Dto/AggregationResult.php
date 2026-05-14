<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Dto;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Immutable DTO for an aggregation result.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class AggregationResult
{
    /**
     * @param string                    $name    Aggregation name.
     * @param string                    $type    Aggregation type.
     * @param float|null                $value   Scalar result (for sum, avg, min, max, cardinality).
     * @param array<string, int|float>  $buckets Bucket results (for histogram, terms, date_histogram).
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?float $value = null,
        public array $buckets = [],
    ) {}
}
