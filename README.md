# monkeyslegion-search

> Multi-engine full-text, hybrid, and semantic search adapter for MonkeysLegion v2 with attribute-driven index syncing, auto-sync observers, queued indexing, and enterprise-grade query features.

[![PHP](https://img.shields.io/badge/PHP-^8.4-8892BF.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/Tests-115%20passed-brightgreen.svg)]()

## Features

| Feature | Description |
|---------|-------------|
| **6 Engine Adapters** | Meilisearch, Typesense, OpenSearch, Elasticsearch, Apache Solr, Null (testing) |
| **SearchableTrait** | Scout-style `toSearchableArray()`, `shouldBeSearchable()`, `search()`, `makeSearchable()` |
| **Auto-Sync Observers** | Automatic index sync on entity create/update/delete via `#[ObservedBy]` or `#[Subscribe]` |
| **Queued Indexing** | Async index operations via `monkeyslegion-queue` integration |
| **Fluent Query Builder** | Chainable API for search, filter, sort, facet, highlight, geo, aggregate |
| **Hybrid Search** | BM25 + vector scoring with configurable weight blending |
| **Geo-Distance Search** | `near()`, `withinRadius()`, `sortByDistance()` with per-hit distance |
| **Advanced Aggregations** | sum, avg, min, max, cardinality, histogram, date_histogram, terms |
| **Autocomplete / Suggest** | Engine-native prefix suggestions across all adapters |
| **Search Scopes** | Reusable, composable query modifiers |
| **Middleware Pipeline** | Intercept queries for logging, analytics, caching, rate limiting |
| **Multi-Index Search** | Cross-index queries with score re-ranking |
| **Raw Engine Queries** | Engine-native DSL passthrough when the Builder isn't enough |
| **Cursor Iteration** | Memory-efficient lazy iteration over large result sets |
| **Batch Reindexing** | Chunked import with progress callbacks + zero-downtime alias swaps |
| **Zero Dependencies** | Internal cURL HTTP client — no PSR-18 or Guzzle required |
| **PHP 8.4 Property Hooks** | Computed properties on DTOs (`$offset`, `$lastPage`, `$isHybrid`, `$isGeo`) |

## Installation

```bash
composer require monkeyscloud/monkeyslegion-search
```

### Requirements

| Requirement | Version |
|-------------|---------|
| PHP | `^8.4` |
| Extensions | `ext-curl`, `ext-json` |
| Optional | `monkeyslegion-queue` (async indexing), `monkeyslegion-entity` (auto-sync) |

## Quick Start

### 1. Mark Your Entity as Searchable

```php
<?php
declare(strict_types=1);

namespace App\Entity;

use MonkeysLegion\Entity\Attributes\{Entity, Field, Id, ObservedBy};
use MonkeysLegion\Search\Attributes\{Searchable, SearchField, SearchIndex};
use MonkeysLegion\Search\Observers\SearchObserver;
use MonkeysLegion\Search\Traits\SearchableTrait;

#[Entity(table: 'products')]
#[Searchable(index: 'products', engine: 'default', autoSync: true)]
#[ObservedBy(SearchObserver::class)]
#[SearchIndex(
    stopWords: ['the', 'a', 'an'],
    synonyms: ['phone' => ['mobile', 'cell', 'smartphone']],
)]
class Product
{
    use SearchableTrait;

    #[Id]
    #[Field(type: 'unsignedBigInt', autoIncrement: true)]
    public private(set) int $id;

    #[Field(type: 'string', length: 255)]
    #[SearchField(searchable: true, filterable: true, sortable: true, weight: 3)]
    public string $name;

    #[Field(type: 'text')]
    #[SearchField(searchable: true, weight: 1)]
    public string $description;

    #[Field(type: 'decimal', precision: 10, scale: 2)]
    #[SearchField(filterable: true, sortable: true)]
    public string $price;

    #[Field(type: 'string', length: 100)]
    #[SearchField(filterable: true, facetable: true)]
    public string $category;

    #[Field(type: 'boolean')]
    #[SearchField(filterable: true)]
    public bool $in_stock;

    #[Field(type: 'string', length: 20)]
    public string $status = 'published';

    // Control exactly what gets indexed
    public function toSearchableArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'price'       => (float) $this->price,
            'category'    => $this->category,
            'in_stock'    => $this->in_stock,
        ];
    }

    // Only index published products
    public function shouldBeSearchable(): bool
    {
        return $this->status === 'published';
    }
}
```

### 2. Configure Search Engines (MLC)

```mlc
# ═══════════════════════════════════════════════════════════════
# Search Configuration
# ═══════════════════════════════════════════════════════════════

search {
    default = ${SEARCH_ENGINE:default}

    engines {
        default {
            driver  = "meilisearch"
            host    = ${MEILISEARCH_HOST:"http://localhost:7700"}
            api_key = ${MEILISEARCH_KEY:""}
        }

        typesense {
            driver  = "typesense"
            host    = ${TYPESENSE_HOST:"http://localhost:8108"}
            api_key = ${TYPESENSE_KEY:""}
        }

        opensearch {
            driver   = "opensearch"
            host     = ${OPENSEARCH_HOST:"http://localhost:9200"}
            username = ${OPENSEARCH_USER:"admin"}
            password = ${OPENSEARCH_PASS:"admin"}
        }

        elasticsearch {
            driver   = "elasticsearch"
            host     = ${ES_HOST:"http://localhost:9200"}
            username = ${ES_USER:""}
            password = ${ES_PASS:""}
        }

        solr {
            driver     = "solr"
            host       = ${SOLR_HOST:"http://localhost:8983"}
            collection = ${SOLR_COLLECTION:"default"}
        }

        # For testing without a running search service
        testing {
            driver = "null"
        }
    }
}
```

### 3. Search with the Fluent Builder

```php
use MonkeysLegion\Search\SearchManager;

final class ProductController
{
    public function __construct(
        private readonly SearchManager $search,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $q = $request->getQueryParams()['q'] ?? '';

        $results = $this->search->index('products')
            ->query($q)
            ->where('in_stock', '=', true)
            ->whereBetween('price', 10.0, 500.0)
            ->facet('category', 'brand')
            ->sortBy('price', SortDirection::Asc)
            ->highlight('name', 'description')
            ->page(1, perPage: 20)
            ->get();

        // $results->total      — Total matching documents
        // $results->hits       — list<SearchHit> on this page
        // $results->facets     — list<Facet> with value counts
        // $results->lastPage   — Total pages (property hook)
        // $results->hasMore    — More pages? (property hook)
        // $results->took       — Query time in ms

        return new Response(json: [
            'data'   => array_map(fn($h) => $h->document, $results->hits),
            'total'  => $results->total,
            'facets' => $results->facets,
        ]);
    }
}
```

## SearchableTrait

The `SearchableTrait` provides a Scout-style API for any entity:

```php
// Static search — returns a Builder for the entity's index
$results = Product::search('wireless headphones')
    ->where('category', '=', 'electronics')
    ->page(1)
    ->get();

// Index a single entity
$product->searchable();

// Remove from index
$product->unsearchable();

// Bulk index multiple entities
Product::makeSearchable($products);

// Bulk remove
Product::makeUnsearchable($products);
```

### Key Methods

| Method | Description |
|--------|-------------|
| `toSearchableArray()` | Override to control indexed data structure |
| `shouldBeSearchable()` | Override for conditional indexing (e.g. skip drafts) |
| `getSearchKey()` | Document ID (defaults to `$id`) |
| `getSearchIndex()` | Index name (from `#[Searchable]` or auto-generated) |
| `static search(string $term)` | Create a fluent Builder for the entity |
| `searchable()` | Index this entity instance |
| `unsearchable()` | Remove this entity from the index |
| `static makeSearchable(iterable)` | Bulk-index entities |
| `static makeUnsearchable(iterable)` | Bulk-remove entities |

## Auto-Sync (Observers)

### Per-Entity Observer

Add `#[ObservedBy(SearchObserver::class)]` to your entity for automatic sync on create/update/delete:

```php
#[Entity(table: 'products')]
#[Searchable(autoSync: true)]
#[ObservedBy(SearchObserver::class)]
class Product { use SearchableTrait; }
```

### Global Subscriber (Zero-Config)

The `SearchSubscriber` listens to ALL entities via `#[Subscribe]`. Just add `#[Searchable]` and `SearchableTrait` — no `#[ObservedBy]` needed:

```php
// Register once at boot
LifecycleDispatcher::registerSubscriber(SearchSubscriber::class);
```

### Lifecycle Events

| Event | Action |
|-------|--------|
| `created` / `saved` | Index document (if `shouldBeSearchable()` is true) |
| `updated` | Re-index or remove (handles status transitions) |
| `deleted` | Remove from index |
| `restored` | Re-index document |

## Queued Indexing

Offload index operations to background workers via `monkeyslegion-queue`:

```php
// Enable per-entity
#[Searchable(autoSync: true, queue: true)]
class Product { ... }

// Or dispatch jobs manually
use MonkeysLegion\Search\Jobs\{IndexDocumentJob, DeleteDocumentJob, BulkIndexJob};

$dispatcher->dispatch(new IndexDocumentJob('products', '42', $document));
$dispatcher->dispatch(new DeleteDocumentJob('products', '42'));
$dispatcher->dispatch(new BulkIndexJob('products', $documents));
```

## Geo-Distance Search

```php
$results = $search->index('restaurants')
    ->query('pizza')
    ->near(latitude: 40.7128, longitude: -74.0060, geoField: 'location')
    ->withinRadius(distanceKm: 5.0)
    ->sortByDistance(latitude: 40.7128, longitude: -74.0060)
    ->get();

// Each hit includes $hit->distance when geo-sorted
foreach ($results->hits as $hit) {
    echo "{$hit->document['name']} — {$hit->distance} km away\n";
}
```

## Advanced Aggregations

Go beyond simple facets with full aggregation support:

```php
$results = $search->index('orders')
    ->query('*')
    ->aggregate('total_revenue', 'sum', 'amount')
    ->aggregate('avg_order', 'avg', 'amount')
    ->aggregate('price_ranges', 'histogram', 'price', ['interval' => 50])
    ->aggregate('orders_per_day', 'date_histogram', 'created_at', ['interval' => 'day'])
    ->aggregate('unique_customers', 'cardinality', 'customer_id')
    ->get();

foreach ($results->aggregations as $agg) {
    echo "{$agg->name}: {$agg->value}\n";           // Scalar aggregations
    foreach ($agg->buckets as $key => $count) {       // Bucket aggregations
        echo "  {$key}: {$count}\n";
    }
}
```

### Supported Aggregation Types

| Type | Output | Description |
|------|--------|-------------|
| `sum` | Scalar | Sum of field values |
| `avg` | Scalar | Average |
| `min` | Scalar | Minimum value |
| `max` | Scalar | Maximum value |
| `cardinality` | Scalar | Unique value count |
| `terms` | Buckets | Top values by count |
| `histogram` | Buckets | Numeric ranges |
| `date_histogram` | Buckets | Date-based ranges |

## Autocomplete / Suggestions

```php
// Via SearchManager
$suggestions = $search->suggest('products', 'wirel', limit: 5);
// Returns: [Suggestion(text: 'wireless headphones'), Suggestion(text: 'wireless mouse')]

// Via Builder
$results = $search->index('products')
    ->suggest('wirel', limit: 5)
    ->get();

foreach ($results->suggestions as $s) {
    echo "{$s->text} (score: {$s->score})\n";
}
```

## Search Scopes

Encapsulate reusable query logic into composable scopes:

```php
use MonkeysLegion\Search\Contracts\SearchScopeInterface;
use MonkeysLegion\Search\Query\Builder;

final class ActiveProductsScope implements SearchScopeInterface
{
    public function apply(Builder $builder): void
    {
        $builder
            ->where('status', '=', 'active')
            ->where('in_stock', '=', true);
    }
}

final class PriceRangeScope implements SearchScopeInterface
{
    public function __construct(
        private readonly float $min,
        private readonly float $max,
    ) {}

    public function apply(Builder $builder): void
    {
        $builder->whereBetween('price', $this->min, $this->max);
    }
}

// Use — compose multiple scopes
$results = $search->index('products')
    ->query('headphones')
    ->scope(new ActiveProductsScope())
    ->scope(new PriceRangeScope(20.0, 200.0))
    ->get();
```

## Middleware Pipeline

Intercept and instrument search queries with a middleware stack:

```php
use MonkeysLegion\Search\Middleware\{LoggingMiddleware, AnalyticsMiddleware};

// Register middleware
$search->pushMiddleware(new LoggingMiddleware($logger));
$search->pushMiddleware($analytics = new AnalyticsMiddleware());

// Execute through pipeline
$result = $search->search($query);

// Analytics data
$analytics->popularTerms(20);      // ['laptop' => 42, 'phone' => 38, ...]
$analytics->zeroResultTerms();     // ['nonexistent', 'typo']
$analytics->averageQueryTime();    // 12.5 (ms)
```

### Custom Middleware

```php
use MonkeysLegion\Search\Middleware\SearchMiddlewareInterface;

final class CachingMiddleware implements SearchMiddlewareInterface
{
    public function handle(SearchQuery $query, callable $next): SearchResult
    {
        $key = md5(serialize($query));
        return $this->cache->remember($key, 60, fn() => $next($query));
    }
}
```

## Multi-Index Search

Search across multiple indexes with merged, score-ranked results:

```php
$results = $search->multiIndex(['products', 'articles', 'categories'])
    ->query('laptop')
    ->page(1, perPage: 20)
    ->get();

// Each hit includes $hit->document['_index'] indicating source
foreach ($results->hits as $hit) {
    echo "[{$hit->document['_index']}] {$hit->document['name']} (score: {$hit->score})\n";
}
```

## Raw Engine Queries

When the Builder abstraction isn't enough, pass engine-native DSL directly:

```php
// Via SearchManager
$results = $search->raw('products', [
    'query' => [
        'function_score' => [
            'query' => ['match' => ['name' => 'laptop']],
            'functions' => [
                ['field_value_factor' => ['field' => 'popularity']],
            ],
        ],
    ],
], engine: 'opensearch');

// Via engine directly
$results = $search->engine('meilisearch')->raw('products', [
    'q'      => 'laptop',
    'limit'  => 5,
    'filter' => 'price > 100',
]);
```

## Cursor Iteration

Memory-efficient streaming for processing large result sets:

```php
// Process millions of documents without loading all into memory
foreach ($search->index('logs')->query('error')->cursor(chunkSize: 100) as $hit) {
    processLogEntry($hit->document);
}
```

## Batch Reindexing

Chunked import with progress callbacks and zero-downtime alias swaps:

```php
use MonkeysLegion\Search\Index\Reindexer;

$reindexer = new Reindexer($search->engine());

// Basic reindex
$total = $reindexer->reindex(
    indexName: 'products',
    dataProvider: fn(int $offset, int $limit) => $repo->findChunk($offset, $limit),
    chunkSize: 500,
    onProgress: fn(int $indexed, int $total) => $output->writeln("{$indexed}/{$total}"),
    totalCount: $repo->count(),
);

// Zero-downtime reindex (OpenSearch/Elasticsearch)
$reindexer->reindex(
    indexName: 'products',
    dataProvider: $provider,
    useAlias: true,  // Creates products_v{timestamp} → atomic alias swap
);

// From entity class
$reindexer->reindexEntity(
    entityClass: Product::class,
    entityLoader: fn(int $offset, int $limit) => $repo->findAll($offset, $limit),
    chunkSize: 500,
);
```

## Hybrid Search (BM25 + Vector)

Combine traditional full-text scoring with vector similarity for semantic search:

```php
$results = $search->index('products')
    ->query('comfortable office chair')
    ->vectorQuery(
        vector: $embeddingService->embed('comfortable office chair'),
        vectorField: 'embedding',
        hybridWeight: 0.6,  // 0.0 = pure BM25, 1.0 = pure vector
    )
    ->page(1, perPage: 10)
    ->get();
```

## Sync Index Schema

```php
// Sync a single entity
$search->syncIndex(Product::class);

// Sync multiple entities
$search->syncAll([Product::class, Article::class, User::class]);
```

## Attributes Reference

### `#[Searchable]` — Class Level

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `index` | `?string` | Auto-generated | Custom index name |
| `engine` | `string` | `'default'` | Engine connection name |
| `autoSync` | `bool` | `true` | Auto-index on persist/delete |
| `queue` | `bool` | `false` | Queue indexing via monkeyslegion-queue |
| `idField` | `string` | `'id'` | Property used as document ID |

### `#[SearchField]` — Property Level

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `searchable` | `bool` | `true` | Include in full-text search |
| `filterable` | `bool` | `false` | Allow filter expressions |
| `sortable` | `bool` | `false` | Allow sorting |
| `facetable` | `bool` | `false` | Generate facet counts |
| `weight` | `int` | `1` | Relevance weight multiplier |
| `analyzer` | `?string` | `null` | Custom analyzer (engine-specific) |
| `as` | `?string` | `null` | Custom field name in index |

### `#[SearchIndex]` — Class Level

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `stopWords` | `list<string>` | `[]` | Words to ignore |
| `synonyms` | `array<string, list<string>>` | `[]` | Synonym groups |
| `rankingRules` | `list<string>` | `[]` | Engine-specific ranking |
| `tokenSeparators` | `list<string>` | `[]` | Custom token separators |
| `maxTotalHits` | `?int` | `null` | Max retrievable hits |
| `extra` | `array<string, mixed>` | `[]` | Raw engine settings |

## Engine Comparison

| Feature | Meilisearch | Typesense | OpenSearch | Elasticsearch | Solr |
|---------|:-----------:|:---------:|:----------:|:-------------:|:----:|
| Full-text search | ✓ | ✓ | ✓ | ✓ | ✓ |
| Filtering | ✓ | ✓ | ✓ | ✓ | ✓ |
| Faceting | ✓ | ✓ | ✓ | ✓ | ✓ |
| Sorting | ✓ | ✓ | ✓ | ✓ | ✓ |
| Highlighting | ✓ | ✓ | ✓ | ✓ | ✓ |
| Vector / kNN | ○ | ✓ | ✓ | ✓ | ○ |
| Hybrid search | ○ | ✓ | ✓ | ✓ | ○ |
| Geo search | ✓ | ✓ | ✓ | ✓ | ✓ |
| Autocomplete | ✓ | ✓ | ✓ | ✓ | ✓ |
| Raw queries | ✓ | ✓ | ✓ | ✓ | ✓ |
| Index aliases | ✗ | ✗ | ✓ | ✓ | ✗ |
| Default port | 7700 | 8108 | 9200 | 9200 | 8983 |

✓ = Supported  ○ = Limited/Experimental

## Architecture

```
SearchManager (facade)
├── engine('meilisearch')   → MeilisearchEngine
├── engine('typesense')     → TypesenseEngine
├── engine('opensearch')    → OpenSearchEngine
├── engine('elasticsearch') → ElasticsearchEngine
├── engine('solr')          → SolrEngine
└── engine('null')          → NullEngine (testing)
     ↑
     └── All implement SearchEngineInterface
         ├── search()  → SearchResult
         ├── raw()     → SearchResult (engine-native DSL)
         ├── suggest() → list<Suggestion>
         └── Uses HttpClient (internal cURL wrapper)

Builder (fluent query)
├── query(), where(), facet(), sortBy(), highlight()
├── near(), withinRadius(), sortByDistance()          ← Geo
├── aggregate()                                       ← Aggregations
├── suggest()                                         ← Autocomplete
├── scope()                                           ← Reusable scopes
├── cursor()                                          ← Lazy iteration
└── Builds SearchQuery DTO → engine.search() → SearchResult DTO

SearchableTrait (entity integration)
├── toSearchableArray(), shouldBeSearchable()
├── searchable(), unsearchable()
├── static search(), makeSearchable(), makeUnsearchable()
└── Hooks into SearchObserver / SearchSubscriber

Middleware Pipeline
├── LoggingMiddleware    → Query/result logging
├── AnalyticsMiddleware  → Popular terms, zero-results
└── Custom middleware    → Caching, rate limiting, etc.

Reindexer
├── Chunked import with progress callbacks
├── AliasManager → Zero-downtime reindex (ES/OpenSearch)
└── Entity-aware reindexing from class metadata
```

## Testing

```bash
# Run all tests (115 tests, 208 assertions)
composer test

# Unit tests only
composer test:unit

# PHPStan level 9
composer phpstan

# PSR-12 code style
composer cs

# Full check (CS + PHPStan + Tests)
composer check
```

## License

MIT — see [LICENSE](LICENSE).
