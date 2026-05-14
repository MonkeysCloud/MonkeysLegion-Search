<?php
declare(strict_types=1);

namespace MonkeysLegion\Search;

use MonkeysLegion\DI\Attributes\Singleton;
use MonkeysLegion\Search\Contracts\SearchEngineInterface;
use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Dto\SearchResult;
use MonkeysLegion\Search\Dto\Suggestion;
use MonkeysLegion\Search\Engine\ElasticsearchEngine;
use MonkeysLegion\Search\Engine\MeilisearchEngine;
use MonkeysLegion\Search\Engine\NullEngine;
use MonkeysLegion\Search\Engine\OpenSearchEngine;
use MonkeysLegion\Search\Engine\SolrEngine;
use MonkeysLegion\Search\Engine\TypesenseEngine;
use MonkeysLegion\Search\Enum\EngineDriver;
use MonkeysLegion\Search\Exceptions\SearchException;
use MonkeysLegion\Search\Index\IndexManager;
use MonkeysLegion\Search\Index\IndexSyncer;
use MonkeysLegion\Search\Middleware\SearchMiddlewareInterface;
use MonkeysLegion\Search\Query\Builder;
use MonkeysLegion\Search\Query\MultiIndexBuilder;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Multi-engine search manager.
 *
 * Central facade for resolving engine adapters by name,
 * constructing query builders, and managing index sync.
 * Supports multiple simultaneous engine connections.
 *
 * ```php
 * // Default engine
 * $results = $search->index('products')
 *     ->query('wireless headphones')
 *     ->where('status', '=', 'active')
 *     ->get();
 *
 * // Specific engine
 * $results = $search->engine('typesense')
 *     ->search($query);
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Singleton]
final class SearchManager
{
    /** @var array<string, SearchEngineInterface> Resolved engine instances. */
    private array $engines = [];

    /** @var array<string, array<string, mixed>> Engine configurations. */
    private array $engineConfigs = [];

    private string $defaultEngine = 'default';
    private IndexSyncer $syncer;

    /** @var list<SearchMiddlewareInterface> */
    private array $middleware = [];

    /**
     * @param array<string, mixed> $config Full search configuration.
     */
    public function __construct(
        private readonly array $config = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->syncer = new IndexSyncer();
        $this->defaultEngine = (string) ($config['default'] ?? 'default');
        $this->engineConfigs = $this->parseEngineConfigs($config);
    }

    // ── Engine Resolution ──────────────────────────────────────

    /**
     * The resolved default engine driver.
     */
    public EngineDriver $defaultDriver {
        get {
            $cfg = $this->engineConfigs[$this->defaultEngine] ?? [];
            $driverStr = (string) ($cfg['driver'] ?? 'null');
            return EngineDriver::tryFrom($driverStr) ?? EngineDriver::Null;
        }
    }

    /**
     * Resolve a named engine adapter (or default).
     *
     * @param string|null $name Engine connection name from config.
     */
    public function engine(?string $name = null): SearchEngineInterface
    {
        $name ??= $this->defaultEngine;

        if (isset($this->engines[$name])) {
            return $this->engines[$name];
        }

        $cfg = $this->engineConfigs[$name] ?? null;

        if ($cfg === null) {
            throw new SearchException("Unknown search engine connection: {$name}");
        }

        $driver = EngineDriver::tryFrom((string) ($cfg['driver'] ?? 'null')) ?? EngineDriver::Null;

        $this->engines[$name] = $this->createEngine($driver, $cfg);

        return $this->engines[$name];
    }

    // ── Query Builder ──────────────────────────────────────────

    /**
     * Create a fluent query builder for the given index.
     *
     * @param string      $indexName Index to search.
     * @param string|null $engine    Engine connection name.
     */
    public function index(string $indexName, ?string $engine = null): Builder
    {
        return new Builder(
            engine: $this->engine($engine),
            indexName: $indexName,
        );
    }

    // ── Index Syncing ──────────────────────────────────────────

    /**
     * Sync a single entity's search index schema.
     *
     * @param class-string $entityClass
     * @param string|null  $engine Engine connection name.
     */
    public function syncIndex(string $entityClass, ?string $engine = null): void
    {
        $mgr = new IndexManager(
            engine: $this->engine($engine),
            syncer: $this->syncer,
            logger: $this->logger,
        );

        $mgr->syncFromEntity($entityClass);
    }

    /**
     * Sync all provided entity classes.
     *
     * @param list<class-string> $entityClasses
     * @param string|null        $engine Engine connection name.
     */
    public function syncAll(array $entityClasses, ?string $engine = null): void
    {
        $mgr = new IndexManager(
            engine: $this->engine($engine),
            syncer: $this->syncer,
            logger: $this->logger,
        );

        foreach ($entityClasses as $class) {
            $mgr->register($class);
        }

        $mgr->syncAll();
    }

    // ── Direct Document Operations ─────────────────────────────

    /**
     * Index a single document.
     *
     * @param string               $indexName Index name.
     * @param string               $id        Document ID.
     * @param array<string, mixed> $document  Document fields.
     * @param string|null          $engine    Engine connection name.
     */
    public function indexDocument(
        string $indexName,
        string $id,
        array $document,
        ?string $engine = null,
    ): void {
        $this->engine($engine)->index($indexName, $id, $document);
    }

    /**
     * Delete a document.
     *
     * @param string      $indexName Index name.
     * @param string      $id        Document ID.
     * @param string|null $engine    Engine connection name.
     */
    public function deleteDocument(
        string $indexName,
        string $id,
        ?string $engine = null,
    ): void {
        $this->engine($engine)->delete($indexName, $id);
    }

    // ── Health ──────────────────────────────────────────────────

    /**
     * Check if the default (or named) engine is reachable.
     */
    public function ping(?string $engine = null): bool
    {
        return $this->engine($engine)->ping();
    }

    /**
     * Get info from the default (or named) engine.
     *
     * @return array<string, mixed>
     */
    public function info(?string $engine = null): array
    {
        return $this->engine($engine)->info();
    }

    /**
     * Register a pre-built engine instance (useful for testing).
     */
    public function registerEngine(string $name, SearchEngineInterface $engine): void
    {
        $this->engines[$name] = $engine;
    }

    // ── Multi-Index Search ───────────────────────────────────

    /**
     * Create a multi-index search builder.
     *
     * @param list<string> $indexNames Indexes to search across.
     * @param string|null  $engine     Engine connection name.
     */
    public function multiIndex(array $indexNames, ?string $engine = null): MultiIndexBuilder
    {
        return new MultiIndexBuilder(
            engine: $this->engine($engine),
            indexNames: $indexNames,
        );
    }

    // ── Suggestions ─────────────────────────────────────────

    /**
     * Get autocomplete suggestions.
     *
     * @param string      $indexName Target index.
     * @param string      $prefix    Partial search term.
     * @param int         $limit     Maximum suggestions.
     * @param string|null $engine    Engine connection name.
     *
     * @return list<Suggestion>
     */
    public function suggest(
        string $indexName,
        string $prefix,
        int $limit = 5,
        ?string $engine = null,
    ): array {
        return $this->engine($engine)->suggest($indexName, $prefix, $limit);
    }

    // ── Middleware Pipeline ─────────────────────────────────

    /**
     * Add a search middleware to the pipeline.
     */
    public function pushMiddleware(SearchMiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * Execute a search query through the middleware pipeline.
     *
     * @param SearchQuery $query  The search query.
     * @param string|null $engine Engine connection name.
     */
    public function search(SearchQuery $query, ?string $engine = null): SearchResult
    {
        $engineInstance = $this->engine($engine);

        // Build the pipeline: engine->search() as the innermost handler
        $handler = static fn(SearchQuery $q): SearchResult => $engineInstance->search($q);

        // Wrap with middleware (outermost first)
        foreach (array_reverse($this->middleware) as $mw) {
            $next = $handler;
            $handler = static fn(SearchQuery $q): SearchResult => $mw->handle($q, $next);
        }

        return $handler($query);
    }

    // ── Raw Engine Queries ──────────────────────────────────

    /**
     * Execute a raw engine-native query.
     *
     * @param string               $indexName Target index.
     * @param array<string, mixed> $rawQuery  Engine-native payload.
     * @param string|null          $engine    Engine connection name.
     */
    public function raw(
        string $indexName,
        array $rawQuery,
        ?string $engine = null,
    ): SearchResult {
        return $this->engine($engine)->raw($indexName, $rawQuery);
    }

    // ── Internal ───────────────────────────────────────────────

    /**
     * Parse engine configurations from the raw config array.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, array<string, mixed>>
     */
    private function parseEngineConfigs(array $config): array
    {
        /** @var array<string, array<string, mixed>> */
        $engines = $config['engines'] ?? [];
        return $engines;
    }

    /**
     * Create an engine adapter from driver and configuration.
     *
     * @param array<string, mixed> $cfg
     */
    private function createEngine(EngineDriver $driver, array $cfg): SearchEngineInterface
    {
        return match ($driver) {
            EngineDriver::Meilisearch => new MeilisearchEngine(
                host: (string) ($cfg['host'] ?? 'http://localhost:7700'),
                apiKey: (string) ($cfg['api_key'] ?? ''),
            ),
            EngineDriver::Typesense => new TypesenseEngine(
                host: (string) ($cfg['host'] ?? 'http://localhost:8108'),
                apiKey: (string) ($cfg['api_key'] ?? ''),
            ),
            EngineDriver::OpenSearch => new OpenSearchEngine(
                host: (string) ($cfg['host'] ?? 'http://localhost:9200'),
                username: (string) ($cfg['username'] ?? ''),
                password: (string) ($cfg['password'] ?? ''),
            ),
            EngineDriver::Elasticsearch => new ElasticsearchEngine(
                host: (string) ($cfg['host'] ?? 'http://localhost:9200'),
                username: (string) ($cfg['username'] ?? ''),
                password: (string) ($cfg['password'] ?? ''),
            ),
            EngineDriver::Solr => new SolrEngine(
                host: (string) ($cfg['host'] ?? 'http://localhost:8983'),
                collection: (string) ($cfg['collection'] ?? 'default'),
                username: (string) ($cfg['username'] ?? ''),
                password: (string) ($cfg['password'] ?? ''),
            ),
            EngineDriver::Null => new NullEngine(),
        };
    }
}
