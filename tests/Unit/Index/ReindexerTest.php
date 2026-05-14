<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit\Index;

use MonkeysLegion\Search\Dto\IndexConfig;
use MonkeysLegion\Search\Engine\NullEngine;
use MonkeysLegion\Search\Index\Reindexer;
use PHPUnit\Framework\TestCase;

/**
 * @copyright 2026 MonkeysCloud Team
 */
final class ReindexerTest extends TestCase
{
    public function testReindexChunkedDocuments(): void
    {
        $engine = new NullEngine();
        $engine->createIndex(new IndexConfig(name: 'reindex_test'));

        $reindexer = new Reindexer($engine);

        // Simulate 23 docs in 10-doc chunks
        $allDocs = [];
        for ($i = 1; $i <= 23; $i++) {
            $allDocs[] = ['id' => (string) $i, 'name' => "Doc {$i}"];
        }

        $total = $reindexer->reindex(
            indexName: 'reindex_test',
            dataProvider: function (int $offset, int $limit) use ($allDocs): array {
                return array_slice($allDocs, $offset, $limit);
            },
            chunkSize: 10,
        );

        self::assertEquals(23, $total);
    }

    public function testProgressCallback(): void
    {
        $engine = new NullEngine();
        $engine->createIndex(new IndexConfig(name: 'progress_test'));

        $reindexer = new Reindexer($engine);

        $docs = [];
        for ($i = 1; $i <= 15; $i++) {
            $docs[] = ['id' => (string) $i, 'name' => "Doc {$i}"];
        }

        $progressCalls = [];
        $reindexer->reindex(
            indexName: 'progress_test',
            dataProvider: fn(int $offset, int $limit) => array_slice($docs, $offset, $limit),
            chunkSize: 5,
            onProgress: function (int $indexed, int $total) use (&$progressCalls): void {
                $progressCalls[] = $indexed;
            },
            totalCount: 15,
        );

        self::assertEquals([5, 10, 15], $progressCalls);
    }

    public function testEmptyDataProvider(): void
    {
        $engine = new NullEngine();
        $engine->createIndex(new IndexConfig(name: 'empty_test'));

        $reindexer = new Reindexer($engine);

        $total = $reindexer->reindex(
            indexName: 'empty_test',
            dataProvider: fn(int $offset, int $limit) => [],
            chunkSize: 10,
        );

        self::assertEquals(0, $total);
    }
}
