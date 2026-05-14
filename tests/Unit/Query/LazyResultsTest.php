<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit\Query;

use MonkeysLegion\Search\Dto\SearchHit;
use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Dto\SearchResult;
use MonkeysLegion\Search\Engine\NullEngine;
use MonkeysLegion\Search\Query\LazyResults;
use PHPUnit\Framework\TestCase;

/**
 * @copyright 2026 MonkeysCloud Team
 */
final class LazyResultsTest extends TestCase
{
    public function testIteratesAllDocuments(): void
    {
        $engine = new NullEngine();
        $engine->createIndex(new \MonkeysLegion\Search\Dto\IndexConfig(name: 'logs'));

        // Insert 25 docs
        for ($i = 1; $i <= 25; $i++) {
            $engine->index('logs', (string) $i, ['id' => (string) $i, 'message' => "Log entry {$i}"]);
        }

        $query = new SearchQuery(indexName: 'logs');
        $lazy = new LazyResults($engine, $query, chunkSize: 10);

        $collected = [];
        foreach ($lazy as $hit) {
            $collected[] = $hit->id;
        }

        self::assertCount(25, $collected);
        self::assertEquals(25, $lazy->totalProcessed());
        self::assertEquals(25, $lazy->totalAvailable());
    }

    public function testEmptyResultSet(): void
    {
        $engine = new NullEngine();
        $engine->createIndex(new \MonkeysLegion\Search\Dto\IndexConfig(name: 'empty'));

        $query = new SearchQuery(indexName: 'empty', term: 'nonexistent');
        $lazy = new LazyResults($engine, $query, chunkSize: 10);

        $collected = [];
        foreach ($lazy as $hit) {
            $collected[] = $hit;
        }

        self::assertCount(0, $collected);
        self::assertEquals(0, $lazy->totalProcessed());
    }

    public function testRespectsTerm(): void
    {
        $engine = new NullEngine();
        $engine->createIndex(new \MonkeysLegion\Search\Dto\IndexConfig(name: 'items'));
        $engine->index('items', '1', ['id' => '1', 'name' => 'alpha']);
        $engine->index('items', '2', ['id' => '2', 'name' => 'beta']);
        $engine->index('items', '3', ['id' => '3', 'name' => 'alpha gamma']);

        $query = new SearchQuery(indexName: 'items', term: 'alpha');
        $lazy = new LazyResults($engine, $query, chunkSize: 10);

        $collected = [];
        foreach ($lazy as $hit) {
            $collected[] = $hit->id;
        }

        self::assertCount(2, $collected);
    }
}
