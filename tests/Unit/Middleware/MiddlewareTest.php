<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit\Middleware;

use MonkeysLegion\Search\Dto\SearchHit;
use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Dto\SearchResult;
use MonkeysLegion\Search\Middleware\AnalyticsMiddleware;
use MonkeysLegion\Search\Middleware\LoggingMiddleware;
use MonkeysLegion\Search\Middleware\SearchMiddlewareInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @copyright 2026 MonkeysCloud Team
 */
final class MiddlewareTest extends TestCase
{
    public function testLoggingMiddlewarePassesThrough(): void
    {
        $logger = new NullLogger();
        $middleware = new LoggingMiddleware($logger);

        $query = new SearchQuery(indexName: 'test', term: 'hello');
        $expected = new SearchResult(hits: [], total: 0, page: 1, perPage: 20, took: 5.0);

        $result = $middleware->handle($query, fn(SearchQuery $q) => $expected);

        self::assertSame($expected, $result);
    }

    public function testAnalyticsTracksPopularTerms(): void
    {
        $analytics = new AnalyticsMiddleware();
        $dummyResult = new SearchResult(hits: [], total: 10, page: 1, perPage: 20, took: 1.0);

        // Search "laptop" 3 times
        for ($i = 0; $i < 3; $i++) {
            $analytics->handle(
                new SearchQuery(indexName: 'test', term: 'Laptop'),
                fn(SearchQuery $q) => $dummyResult,
            );
        }

        // Search "mouse" 1 time
        $analytics->handle(
            new SearchQuery(indexName: 'test', term: 'mouse'),
            fn(SearchQuery $q) => $dummyResult,
        );

        $popular = $analytics->popularTerms(10);
        self::assertArrayHasKey('laptop', $popular);
        self::assertEquals(3, $popular['laptop']);
        self::assertArrayHasKey('mouse', $popular);
        self::assertEquals(1, $popular['mouse']);
    }

    public function testAnalyticsTracksZeroResultTerms(): void
    {
        $analytics = new AnalyticsMiddleware();
        $emptyResult = new SearchResult(hits: [], total: 0, page: 1, perPage: 20);

        $analytics->handle(
            new SearchQuery(indexName: 'test', term: 'nonexistent'),
            fn(SearchQuery $q) => $emptyResult,
        );

        $zeroTerms = $analytics->zeroResultTerms();
        self::assertContains('nonexistent', $zeroTerms);
    }

    public function testAnalyticsAverageQueryTime(): void
    {
        $analytics = new AnalyticsMiddleware();

        $analytics->handle(
            new SearchQuery(indexName: 'test', term: 'fast'),
            fn(SearchQuery $q) => new SearchResult(hits: [], total: 0, page: 1, perPage: 20, took: 2.0),
        );
        $analytics->handle(
            new SearchQuery(indexName: 'test', term: 'slow'),
            fn(SearchQuery $q) => new SearchResult(hits: [], total: 0, page: 1, perPage: 20, took: 8.0),
        );

        self::assertEquals(5.0, $analytics->averageQueryTime());
    }

    public function testAnalyticsReset(): void
    {
        $analytics = new AnalyticsMiddleware();
        $analytics->handle(
            new SearchQuery(indexName: 'test', term: 'test'),
            fn(SearchQuery $q) => new SearchResult(hits: [], total: 0, page: 1, perPage: 20),
        );

        $analytics->reset();

        self::assertEmpty($analytics->log());
        self::assertEmpty($analytics->popularTerms());
        self::assertEmpty($analytics->zeroResultTerms());
    }

    public function testMiddlewarePipeline(): void
    {
        $order = [];

        $m1 = new class($order) implements SearchMiddlewareInterface {
            public function __construct(private array &$order) {}
            public function handle(SearchQuery $query, callable $next): SearchResult
            {
                $this->order[] = 'before_m1';
                $result = $next($query);
                $this->order[] = 'after_m1';
                return $result;
            }
        };

        $m2 = new class($order) implements SearchMiddlewareInterface {
            public function __construct(private array &$order) {}
            public function handle(SearchQuery $query, callable $next): SearchResult
            {
                $this->order[] = 'before_m2';
                $result = $next($query);
                $this->order[] = 'after_m2';
                return $result;
            }
        };

        $query = new SearchQuery(indexName: 'test');
        $innerResult = new SearchResult(hits: [], total: 0, page: 1, perPage: 20);

        // Build pipeline: m1 wraps m2 wraps handler
        $handler = fn(SearchQuery $q) => $innerResult;
        $handler = fn(SearchQuery $q) => $m2->handle($q, $handler);
        $result = $m1->handle($query, $handler);

        self::assertEquals(['before_m1', 'before_m2', 'after_m2', 'after_m1'], $order);
        self::assertSame($innerResult, $result);
    }
}
