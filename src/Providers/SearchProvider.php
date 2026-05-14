<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Providers;

use MonkeysLegion\Core\Attribute\Provider;
use MonkeysLegion\DI\Traits\ContainerAware;
use MonkeysLegion\Search\Contracts\SearchEngineInterface;
use MonkeysLegion\Search\Index\IndexManager;
use MonkeysLegion\Search\Index\IndexSyncer;
use MonkeysLegion\Search\SearchManager;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Service provider for DI container registration.
 *
 * Registers SearchManager, IndexSyncer, IndexManager, and
 * binds SearchEngineInterface to the default engine.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Provider]
final class SearchProvider
{
    use ContainerAware;

    public function register(): void
    {
        // Resolve search config from MLC or fallback
        $config = $this->resolveConfig();

        $logger = $this->has(LoggerInterface::class)
            ? $this->resolve(LoggerInterface::class)
            : new NullLogger();

        // Register SearchManager as singleton
        $manager = new SearchManager(config: $config, logger: $logger);
        $this->bind(SearchManager::class, $manager);

        // Bind the default engine to the interface
        $this->bind(SearchEngineInterface::class, $manager->engine());

        // Register IndexSyncer
        $syncer = new IndexSyncer();
        $this->bind(IndexSyncer::class, $syncer);

        // Register IndexManager with default engine
        $indexManager = new IndexManager(
            engine: $manager->engine(),
            syncer: $syncer,
            logger: $logger,
        );
        $this->bind(IndexManager::class, $indexManager);
    }

    // ── Internal ───────────────────────────────────────────────

    /**
     * Resolve the search configuration from MLC config or defaults.
     *
     * @return array<string, mixed>
     */
    private function resolveConfig(): array
    {
        // Try to load from MLC configuration
        if ($this->has('config')) {
            $allConfig = $this->resolve('config');
            if (is_array($allConfig) && isset($allConfig['search'])) {
                /** @var array<string, mixed> */
                return $allConfig['search'];
            }
        }

        // Fallback: NullEngine for development
        return [
            'default' => 'default',
            'engines' => [
                'default' => [
                    'driver' => 'null',
                ],
            ],
        ];
    }

    /**
     * Bind a resolved instance into the container.
     */
    private function bind(string $id, object $instance): void
    {
        if (method_exists($this, 'set')) {
            $this->set($id, $instance);
        }
    }
}
