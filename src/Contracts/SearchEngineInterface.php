<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Contracts;

use MonkeysLegion\Search\Dto\IndexConfig;
use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Dto\SearchResult;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Core contract for all search engine adapters.
 *
 * Every engine driver (Meilisearch, Typesense, OpenSearch,
 * Elasticsearch, Solr, Null) implements this interface to
 * provide a unified search, indexing, and management API.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface SearchEngineInterface
{
    // ── Search ─────────────────────────────────────────────────

    /**
     * Execute a search query against an index.
     *
     * @param SearchQuery $query Fully-configured query DTO.
     *
     * @return SearchResult Paginated result set with hits, facets, and timing.
     */
    public function search(SearchQuery $query): SearchResult;

    // ── Document Indexing ──────────────────────────────────────

    /**
     * Index a single document.
     *
     * @param string               $indexName Target index.
     * @param string               $id        Document identifier.
     * @param array<string, mixed> $document  Document fields.
     */
    public function index(string $indexName, string $id, array $document): void;

    /**
     * Index multiple documents in a single batch.
     *
     * @param string                       $indexName Target index.
     * @param array<array<string, mixed>>  $documents Documents to index (must include id field).
     *
     * @return int Number of documents indexed.
     */
    public function bulkIndex(string $indexName, array $documents): int;

    /**
     * Delete a single document by its identifier.
     *
     * @param string $indexName Target index.
     * @param string $id        Document identifier.
     */
    public function delete(string $indexName, string $id): void;

    /**
     * Delete multiple documents by their identifiers.
     *
     * @param string   $indexName Target index.
     * @param string[] $ids       Document identifiers.
     *
     * @return int Number of documents deleted.
     */
    public function bulkDelete(string $indexName, array $ids): int;

    // ── Index Management ───────────────────────────────────────

    /**
     * Create an index with the given configuration.
     *
     * @param IndexConfig $config Index schema and settings.
     */
    public function createIndex(IndexConfig $config): void;

    /**
     * Delete an index and all its documents.
     *
     * @param string $indexName Index to delete.
     */
    public function deleteIndex(string $indexName): void;

    /**
     * Check if an index exists.
     *
     * @param string $indexName Index to check.
     */
    public function indexExists(string $indexName): bool;

    /**
     * Update index settings (searchable fields, filterable attributes, etc.).
     *
     * @param string               $indexName Target index.
     * @param array<string, mixed> $settings  Engine-specific settings.
     */
    public function updateSettings(string $indexName, array $settings): void;

    // ── Health & Diagnostics ───────────────────────────────────

    /**
     * Check if the search engine is reachable.
     */
    public function ping(): bool;

    /**
     * Get engine version and status information.
     *
     * @return array<string, mixed>
     */
    public function info(): array;

    // ── Raw Queries ───────────────────────────────────────────

    /**
     * Execute a raw engine-native query.
     *
     * Bypasses the Builder and SearchQuery abstraction to pass
     * engine-specific DSL directly.
     *
     * @param string               $indexName Target index.
     * @param array<string, mixed> $rawQuery  Engine-native query payload.
     *
     * @return SearchResult
     */
    public function raw(string $indexName, array $rawQuery): SearchResult;

    // ── Suggestions ───────────────────────────────────────────

    /**
     * Get autocomplete suggestions for a prefix.
     *
     * @param string $indexName Target index.
     * @param string $prefix   Partial search term.
     * @param int    $limit    Maximum suggestions to return.
     *
     * @return list<\MonkeysLegion\Search\Dto\Suggestion>
     */
    public function suggest(string $indexName, string $prefix, int $limit = 5): array;
}
