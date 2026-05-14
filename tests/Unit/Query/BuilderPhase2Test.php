<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit\Query;

use MonkeysLegion\Search\Contracts\SearchScopeInterface;
use MonkeysLegion\Search\Dto\IndexConfig;
use MonkeysLegion\Search\Engine\NullEngine;
use MonkeysLegion\Search\Query\Builder;
use MonkeysLegion\Search\Query\MultiIndexBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 builder feature tests.
 *
 * @copyright 2026 MonkeysCloud Team
 */
final class BuilderPhase2Test extends TestCase
{
    private NullEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new NullEngine();
    }

    public function testGeoNear(): void
    {
        $builder = new Builder($this->engine, 'restaurants');
        $query = $builder
            ->query('pizza')
            ->near(40.7128, -74.0060, '_geo')
            ->withinRadius(5.0)
            ->toQuery();

        self::assertEquals(40.7128, $query->geoLat);
        self::assertEquals(-74.0060, $query->geoLng);
        self::assertEquals(5.0, $query->geoRadius);
        self::assertEquals('_geo', $query->geoField);
        self::assertTrue($query->isGeo);
    }

    public function testSortByDistance(): void
    {
        $builder = new Builder($this->engine, 'restaurants');
        $query = $builder
            ->sortByDistance(40.7128, -74.0060)
            ->toQuery();

        self::assertTrue($query->geoSort);
        self::assertTrue($query->isGeo);
    }

    public function testAggregate(): void
    {
        $builder = new Builder($this->engine, 'orders');
        $query = $builder
            ->aggregate('total_revenue', 'sum', 'amount')
            ->aggregate('avg_order', 'avg', 'amount')
            ->aggregate('price_histogram', 'histogram', 'price', ['interval' => 50])
            ->toQuery();

        self::assertCount(3, $query->aggregations);
        self::assertEquals('total_revenue', $query->aggregations[0]->name);
        self::assertEquals('sum', $query->aggregations[0]->type);
        self::assertEquals('amount', $query->aggregations[0]->field);
        self::assertEquals('histogram', $query->aggregations[2]->type);
        self::assertEquals(50, $query->aggregations[2]->options['interval']);
    }

    public function testSuggest(): void
    {
        $builder = new Builder($this->engine, 'products');
        $query = $builder->suggest('wire', 10)->toQuery();

        self::assertEquals('wire', $query->suggestTerm);
        self::assertEquals(10, $query->suggestLimit);
    }

    public function testScope(): void
    {
        $scope = new class implements SearchScopeInterface {
            public function apply(Builder $builder): void
            {
                $builder
                    ->where('status', '=', 'active')
                    ->where('in_stock', '=', true);
            }
        };

        $builder = new Builder($this->engine, 'products');
        $query = $builder
            ->query('headphones')
            ->scope($scope)
            ->toQuery();

        self::assertCount(2, $query->filters);
        self::assertEquals('status', $query->filters[0]['field']);
        self::assertEquals('in_stock', $query->filters[1]['field']);
    }

    public function testCursorReturnsLazyResults(): void
    {
        $this->engine->createIndex(new IndexConfig(name: 'items'));
        for ($i = 1; $i <= 5; $i++) {
            $this->engine->index('items', (string) $i, ['id' => (string) $i, 'name' => "Item {$i}"]);
        }

        $builder = new Builder($this->engine, 'items');
        $lazy = $builder->cursor(chunkSize: 2);

        $collected = [];
        foreach ($lazy as $hit) {
            $collected[] = $hit->id;
        }

        self::assertCount(5, $collected);
    }

    public function testMultiIndexSearch(): void
    {
        $this->engine->createIndex(new IndexConfig(name: 'products'));
        $this->engine->createIndex(new IndexConfig(name: 'articles'));
        $this->engine->index('products', '1', ['id' => '1', 'name' => 'laptop stand']);
        $this->engine->index('articles', '2', ['id' => '2', 'title' => 'best laptop review']);

        $multi = new MultiIndexBuilder($this->engine, ['products', 'articles']);
        $result = $multi->query('laptop')->get();

        self::assertEquals(2, $result->total);
    }

    public function testNullEngineRaw(): void
    {
        $this->engine->createIndex(new IndexConfig(name: 'test'));
        $this->engine->index('test', '1', ['id' => '1', 'name' => 'raw test']);

        $result = $this->engine->raw('test', ['q' => '*']);

        self::assertInstanceOf(\MonkeysLegion\Search\Dto\SearchResult::class, $result);
    }

    public function testNullEngineSuggest(): void
    {
        $this->engine->createIndex(new IndexConfig(name: 'products'));
        $this->engine->index('products', '1', ['id' => '1', 'name' => 'wireless headphones']);
        $this->engine->index('products', '2', ['id' => '2', 'name' => 'wireless mouse']);
        $this->engine->index('products', '3', ['id' => '3', 'name' => 'wired keyboard']);

        $suggestions = $this->engine->suggest('products', 'wire', 5);

        self::assertNotEmpty($suggestions);
        self::assertContainsOnlyInstancesOf(\MonkeysLegion\Search\Dto\Suggestion::class, $suggestions);
    }

    public function testIsGeoFalseByDefault(): void
    {
        $query = new \MonkeysLegion\Search\Dto\SearchQuery(indexName: 'test');
        self::assertFalse($query->isGeo);
    }

    public function testSearchHitDistance(): void
    {
        $hit = new \MonkeysLegion\Search\Dto\SearchHit(
            id: '1',
            score: 1.0,
            document: [],
            distance: 2.5,
        );

        self::assertEquals(2.5, $hit->distance);
    }

    public function testSearchResultAggregations(): void
    {
        $agg = new \MonkeysLegion\Search\Dto\AggregationResult(
            name: 'total_revenue',
            type: 'sum',
            value: 1234.56,
        );

        $result = new \MonkeysLegion\Search\Dto\SearchResult(
            hits: [],
            total: 0,
            page: 1,
            perPage: 20,
            aggregations: [$agg],
        );

        self::assertCount(1, $result->aggregations);
        self::assertEquals('total_revenue', $result->aggregations[0]->name);
        self::assertEquals(1234.56, $result->aggregations[0]->value);
    }

    public function testSearchResultSuggestions(): void
    {
        $sug = new \MonkeysLegion\Search\Dto\Suggestion(text: 'wireless headphones', score: 0.95);

        $result = new \MonkeysLegion\Search\Dto\SearchResult(
            hits: [],
            total: 0,
            page: 1,
            perPage: 20,
            suggestions: [$sug],
        );

        self::assertCount(1, $result->suggestions);
        self::assertEquals('wireless headphones', $result->suggestions[0]->text);
    }
}
