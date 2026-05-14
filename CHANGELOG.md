# Changelog

All notable changes to `monkeyslegion-search` will be documented in this file.

## [1.0.0] — 2026-05-14

### Added
- Core contracts: `SearchEngineInterface`, `IndexManagerInterface`, `QueryBuilderInterface`
- Attribute-driven indexing: `#[Searchable]`, `#[SearchField]`, `#[SearchIndex]`
- DTOs: `SearchQuery`, `SearchResult`, `SearchHit`, `Facet`, `IndexConfig`
- Fluent `Builder` for constructing search queries
- Index syncing from Entity metadata via `IndexSyncer`
- Engine adapters: Meilisearch, Typesense, OpenSearch, Elasticsearch, Solr, Null
- `SearchManager` multi-engine facade with driver resolution
- `SearchProvider` for DI container registration
- Hybrid BM25+vector search support (OpenSearch, Elasticsearch, Typesense)
- Internal cURL-based HTTP client for zero external dependencies
- Backed enums: `EngineDriver`, `SortDirection`, `FieldType`
