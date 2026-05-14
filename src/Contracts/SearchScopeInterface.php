<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Contracts;

use MonkeysLegion\Search\Query\Builder;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Contract for reusable search scopes.
 *
 * A scope encapsulates a common set of query modifications
 * (filters, sorts, etc.) that can be applied to any Builder.
 *
 * ```php
 * final class ActiveProductsScope implements SearchScopeInterface
 * {
 *     public function apply(Builder $builder): void
 *     {
 *         $builder
 *             ->where('status', '=', 'active')
 *             ->where('in_stock', '=', true);
 *     }
 * }
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface SearchScopeInterface
{
    /**
     * Apply query modifications to the builder.
     *
     * @param Builder $builder The query builder to modify.
     */
    public function apply(Builder $builder): void;
}
