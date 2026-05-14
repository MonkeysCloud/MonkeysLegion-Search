<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit;

use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Dto\SearchResult;
use MonkeysLegion\Search\Engine\NullEngine;
use MonkeysLegion\Search\Middleware\AnalyticsMiddleware;
use MonkeysLegion\Search\SearchManager;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 SearchManager feature tests.
 *
 * @copyright 2026 MonkeysCloud Team
 */
final class SearchManagerPhase2Test extends TestCase
{
    public function testMultiIndexCreatesBuilder(): void
    {
        $engine = new NullEngine();
        $manager = new SearchManager();
        $manager->registerEngine('default', $engine);

        $builder = $manager->multiIndex(['products', 'articles']);
        self::assertInstanceOf(\MonkeysLegion\Search\Query\MultiIndexBuilder::class, $builder);
    }

    public function testSuggestDelegatesToEngine(): void
    {
        $engine = new NullEngine();
        $engine->createIndex(new \MonkeysLegion\Search\Dto\IndexConfig(name: 'products'));
        $engine->index('products', '1', ['id' => '1', 'name' => 'wireless mouse']);

        $manager = new SearchManager();
        $manager->registerEngine('default', $engine);

        $suggestions = $manager->suggest('products', 'wire');
        self::assertNotEmpty($suggestions);
    }

    public function testRawDelegatesToEngine(): void
    {
        $engine = new NullEngine();
        $engine->createIndex(new \MonkeysLegion\Search\Dto\IndexConfig(name: 'test'));

        $manager = new SearchManager();
        $manager->registerEngine('default', $engine);

        $result = $manager->raw('test', ['q' => '*']);
        self::assertInstanceOf(SearchResult::class, $result);
    }

    public function testMiddlewarePipeline(): void
    {
        $engine = new NullEngine();
        $engine->createIndex(new \MonkeysLegion\Search\Dto\IndexConfig(name: 'test'));
        $engine->index('test', '1', ['id' => '1', 'name' => 'hello world']);

        $analytics = new AnalyticsMiddleware();

        $manager = new SearchManager();
        $manager->registerEngine('default', $engine);
        $manager->pushMiddleware($analytics);

        $query = new SearchQuery(indexName: 'test', term: 'hello');
        $result = $manager->search($query);

        self::assertGreaterThan(0, $result->total);
        self::assertNotEmpty($analytics->log());
        self::assertArrayHasKey('hello', $analytics->popularTerms());
    }
}
