<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Index;

use MonkeysLegion\Search\Contracts\SearchEngineInterface;
use MonkeysLegion\Search\Dto\IndexConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Chunked reindexer with progress callbacks and zero-downtime support.
 *
 * Processes large datasets in configurable chunks, optionally using
 * index aliases for atomic swap (OpenSearch/Elasticsearch).
 *
 * ```php
 * $reindexer->reindex(
 *     indexName: 'products',
 *     dataProvider: fn(int $offset, int $limit) => $repo->findChunk($offset, $limit),
 *     chunkSize: 500,
 *     onProgress: fn(int $indexed, int $total) => $output->writeln("{$indexed}/{$total}"),
 * );
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class Reindexer
{
    public function __construct(
        private readonly SearchEngineInterface $engine,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?AliasManager $aliasManager = null,
    ) {}

    /**
     * Reindex an entire index using a data provider callable.
     *
     * @param string                                                  $indexName    Target index.
     * @param callable(int, int): list<array<string, mixed>>          $dataProvider Returns docs for offset+limit.
     * @param int                                                     $chunkSize   Docs per batch.
     * @param (callable(int, int): void)|null                         $onProgress  Progress callback (indexed, total).
     * @param int|null                                                $totalCount  Known total (skip counting).
     * @param bool                                                    $useAlias    Use alias swap for zero-downtime.
     *
     * @return int Total documents indexed.
     */
    public function reindex(
        string $indexName,
        callable $dataProvider,
        int $chunkSize = 500,
        ?callable $onProgress = null,
        ?int $totalCount = null,
        bool $useAlias = false,
    ): int {
        $targetIndex = $indexName;

        // Zero-downtime: create versioned index + alias swap
        if ($useAlias && $this->aliasManager !== null) {
            $targetIndex = $this->aliasManager->createVersionedIndex($indexName);
            $this->logger->info("Zero-downtime reindex: writing to {$targetIndex}");
        }

        $offset = 0;
        $totalIndexed = 0;

        while (true) {
            $chunk = $dataProvider($offset, $chunkSize);

            if ($chunk === []) {
                break;
            }

            $count = $this->engine->bulkIndex($targetIndex, $chunk);
            $totalIndexed += $count;
            $offset += $chunkSize;

            if ($onProgress !== null) {
                $onProgress($totalIndexed, $totalCount ?? $totalIndexed);
            }

            $this->logger->debug("Reindex chunk: {$totalIndexed} docs indexed", [
                'index' => $targetIndex,
                'chunk' => count($chunk),
            ]);

            // If chunk returned fewer than requested, we're done
            if (count($chunk) < $chunkSize) {
                break;
            }
        }

        // Zero-downtime: swap alias and cleanup
        if ($useAlias && $this->aliasManager !== null) {
            $this->aliasManager->swap($indexName, $targetIndex);
            $this->aliasManager->cleanup($indexName, keepVersions: 2);
            $this->logger->info("Alias swapped: {$indexName} → {$targetIndex}");
        }

        $this->logger->info("Reindex complete: {$totalIndexed} docs in {$indexName}");

        return $totalIndexed;
    }

    /**
     * Reindex from a searchable entity class.
     *
     * @param class-string                                   $entityClass  Entity class.
     * @param callable(int, int): list<object>               $entityLoader Loads entities by offset+limit.
     * @param int                                            $chunkSize    Docs per batch.
     * @param (callable(int, int): void)|null                $onProgress   Progress callback.
     * @param bool                                           $useAlias     Zero-downtime alias swap.
     *
     * @return int Total documents indexed.
     */
    public function reindexEntity(
        string $entityClass,
        callable $entityLoader,
        int $chunkSize = 500,
        ?callable $onProgress = null,
        bool $useAlias = false,
    ): int {
        $syncer = new IndexSyncer();
        $config = $syncer->buildConfig($entityClass);

        // Ensure index exists
        if (!$this->engine->indexExists($config->name)) {
            $this->engine->createIndex($config);
        }

        return $this->reindex(
            indexName: $config->name,
            dataProvider: function (int $offset, int $limit) use ($entityLoader): array {
                $entities = $entityLoader($offset, $limit);
                $docs = [];
                foreach ($entities as $entity) {
                    if (method_exists($entity, 'shouldBeSearchable') && !$entity->shouldBeSearchable()) {
                        continue;
                    }
                    if (method_exists($entity, 'toSearchableArray')) {
                        $docs[] = $entity->toSearchableArray();
                    }
                }
                return $docs;
            },
            chunkSize: $chunkSize,
            onProgress: $onProgress,
            useAlias: $useAlias,
        );
    }
}
