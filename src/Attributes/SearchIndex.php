<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Override index-level settings for a searchable entity.
 *
 * Applied at class level alongside `#[Searchable]` to configure
 * engine-specific settings such as stop words, synonyms,
 * ranking rules, and token separators.
 *
 * ```php
 * #[Entity(table: 'articles')]
 * #[Searchable]
 * #[SearchIndex(
 *     stopWords: ['the', 'a', 'an'],
 *     synonyms: ['phone' => ['mobile', 'cell']],
 * )]
 * class Article { }
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class SearchIndex
{
    /**
     * @param list<string>                    $stopWords       Words to ignore during indexing.
     * @param array<string, list<string>>     $synonyms        Synonym groups.
     * @param list<string>                    $rankingRules    Engine-specific ranking rules.
     * @param list<string>                    $tokenSeparators Custom token separators.
     * @param int|null                        $maxTotalHits    Max retrievable hits.
     * @param array<string, mixed>            $extra           Engine-specific raw settings.
     */
    public function __construct(
        public readonly array $stopWords = [],
        public readonly array $synonyms = [],
        public readonly array $rankingRules = [],
        public readonly array $tokenSeparators = [],
        public readonly ?int $maxTotalHits = null,
        public readonly array $extra = [],
    ) {}
}
