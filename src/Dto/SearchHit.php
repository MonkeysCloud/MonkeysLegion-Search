<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Dto;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Immutable DTO representing a single search hit (matched document).
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class SearchHit
{
    /**
     * @param string               $id         Document identifier.
     * @param float                $score      Relevance score (engine-specific).
     * @param array<string, mixed> $document   Full document fields.
     * @param array<string, string> $highlights Highlighted field snippets (field => snippet).
     * @param float|null           $distance   Geo distance in km (when geo-sorted).
     */
    public function __construct(
        public string $id,
        public float $score,
        public array $document,
        public array $highlights = [],
        public ?float $distance = null,
    ) {}
}
