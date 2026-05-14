<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Dto;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Immutable DTO for an autocomplete/search suggestion.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class Suggestion
{
    /**
     * @param string  $text       Suggested text.
     * @param float   $score      Relevance score.
     * @param string  $highlight  Highlighted version of the text.
     */
    public function __construct(
        public string $text,
        public float $score = 1.0,
        public string $highlight = '',
    ) {}
}
