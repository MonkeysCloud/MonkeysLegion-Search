<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit\Query;

use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Dto\SearchResult;
use MonkeysLegion\Search\Engine\NullEngine;
use MonkeysLegion\Search\Enum\SortDirection;
use MonkeysLegion\Search\Query\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Builder::class)]
final class BuilderTest extends TestCase
{
    private NullEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new NullEngine();
    }

    #[Test]
    public function to_query_builds_correct_dto(): void
    {
        $builder = new Builder($this->engine, 'products');

        $query = $builder
            ->query('headphones')
            ->where('status', '=', 'active')
            ->sortBy('price', SortDirection::Asc)
            ->page(2, perPage: 15)
            ->highlight('name', 'description')
            ->facet('category', 'brand')
            ->toQuery();

        $this->assertSame('products', $query->indexName);
        $this->assertSame('headphones', $query->term);
        $this->assertCount(1, $query->filters);
        $this->assertSame('status', $query->filters[0]['field']);
        $this->assertSame('=', $query->filters[0]['operator']);
        $this->assertSame('active', $query->filters[0]['value']);
        $this->assertCount(1, $query->sorts);
        $this->assertSame(2, $query->page);
        $this->assertSame(15, $query->perPage);
        $this->assertSame(['name', 'description'], $query->highlightFields);
        $this->assertSame(['category', 'brand'], $query->facets);
    }

    #[Test]
    public function where_between_adds_range_filter(): void
    {
        $builder = new Builder($this->engine, 'products');
        $query = $builder->whereBetween('price', 10, 100)->toQuery();

        $this->assertCount(1, $query->filters);
        $this->assertSame('BETWEEN', $query->filters[0]['operator']);
        $this->assertSame([10, 100], $query->filters[0]['value']);
    }

    #[Test]
    public function where_in_adds_in_filter(): void
    {
        $builder = new Builder($this->engine, 'products');
        $query = $builder->whereIn('category', ['electronics', 'clothing'])->toQuery();

        $this->assertCount(1, $query->filters);
        $this->assertSame('IN', $query->filters[0]['operator']);
    }

    #[Test]
    public function where_not_in_adds_not_in_filter(): void
    {
        $builder = new Builder($this->engine, 'products');
        $query = $builder->whereNotIn('status', ['deleted', 'archived'])->toQuery();

        $this->assertSame('NOT IN', $query->filters[0]['operator']);
    }

    #[Test]
    public function vector_query_sets_hybrid_params(): void
    {
        $builder = new Builder($this->engine, 'products');
        $query = $builder
            ->query('headphones')
            ->vectorQuery([0.1, 0.2, 0.3], 'embedding', 0.7)
            ->toQuery();

        $this->assertTrue($query->isHybrid);
        $this->assertSame([0.1, 0.2, 0.3], $query->vector);
        $this->assertSame('embedding', $query->vectorField);
        $this->assertSame(0.7, $query->hybridWeight);
    }

    #[Test]
    public function select_limits_returned_fields(): void
    {
        $builder = new Builder($this->engine, 'products');
        $query = $builder->select(['name', 'price'])->toQuery();

        $this->assertSame(['name', 'price'], $query->selectFields);
    }

    #[Test]
    public function get_executes_search_and_returns_result(): void
    {
        $this->engine->createIndex(new \MonkeysLegion\Search\Dto\IndexConfig(name: 'products'));
        $this->engine->index('products', '1', ['name' => 'Wireless Headphones']);

        $builder = new Builder($this->engine, 'products');
        $result = $builder->query('wireless')->get();

        $this->assertInstanceOf(SearchResult::class, $result);
        $this->assertSame(1, $result->total);
    }

    #[Test]
    public function page_clamps_values(): void
    {
        $builder = new Builder($this->engine, 'products');
        $query = $builder->page(-1, perPage: 5000)->toQuery();

        $this->assertSame(1, $query->page);
        $this->assertSame(1000, $query->perPage);
    }

    #[Test]
    public function with_options_merges_extra(): void
    {
        $builder = new Builder($this->engine, 'products');
        $query = $builder
            ->withOptions(['showRankingScore' => true])
            ->withOptions(['matchingStrategy' => 'all'])
            ->toQuery();

        $this->assertSame(true, $query->extra['showRankingScore']);
        $this->assertSame('all', $query->extra['matchingStrategy']);
    }

    #[Test]
    public function facet_deduplicates_fields(): void
    {
        $builder = new Builder($this->engine, 'products');
        $query = $builder->facet('category', 'brand')->facet('category')->toQuery();

        $this->assertSame(['category', 'brand'], $query->facets);
    }
}
