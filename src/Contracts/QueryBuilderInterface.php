<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Contracts;

use MonkeysLegion\Search\Dto\SearchResult;
use MonkeysLegion\Search\Enum\SortDirection;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Fluent query builder contract for constructing search queries.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface QueryBuilderInterface
{
    /**
     * Set the full-text search query string.
     */
    public function query(string $term): static;

    /**
     * Add an equality filter.
     */
    public function where(string $field, string $operator, mixed $value): static;

    /**
     * Add a range filter (inclusive).
     */
    public function whereBetween(string $field, mixed $min, mixed $max): static;

    /**
     * Add an IN filter.
     *
     * @param list<mixed> $values
     */
    public function whereIn(string $field, array $values): static;

    /**
     * Add a NOT IN filter.
     *
     * @param list<mixed> $values
     */
    public function whereNotIn(string $field, array $values): static;

    /**
     * Request facets (aggregated value counts).
     */
    public function facet(string ...$fields): static;

    /**
     * Add a sort criterion.
     */
    public function sortBy(string $field, SortDirection $direction = SortDirection::Asc): static;

    /**
     * Set pagination.
     */
    public function page(int $page, int $perPage = 20): static;

    /**
     * Enable field highlighting.
     */
    public function highlight(string ...$fields): static;

    /**
     * Limit which fields are returned in results.
     *
     * @param list<string> $fields
     */
    public function select(array $fields): static;

    /**
     * Set a vector query for hybrid search.
     *
     * @param list<float> $vector       Query vector.
     * @param string      $vectorField  Field containing vectors.
     * @param float       $hybridWeight 0.0 = pure BM25, 1.0 = pure vector.
     */
    public function vectorQuery(
        array $vector,
        string $vectorField,
        float $hybridWeight = 0.5,
    ): static;

    /**
     * Execute the query and return results.
     */
    public function get(): SearchResult;
}
