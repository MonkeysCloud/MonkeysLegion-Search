<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit\Dto;

use MonkeysLegion\Search\Dto\Facet;
use MonkeysLegion\Search\Dto\SearchHit;
use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Dto\SearchResult;
use MonkeysLegion\Search\Enum\SortDirection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchQuery::class)]
#[CoversClass(SearchResult::class)]
#[CoversClass(SearchHit::class)]
#[CoversClass(Facet::class)]
final class DtoTest extends TestCase
{
    #[Test]
    public function search_query_offset_computed_property(): void
    {
        $q = new SearchQuery(indexName: 'test', page: 3, perPage: 10);
        $this->assertSame(20, $q->offset);
    }

    #[Test]
    public function search_query_is_hybrid_false_by_default(): void
    {
        $q = new SearchQuery(indexName: 'test');
        $this->assertFalse($q->isHybrid);
    }

    #[Test]
    public function search_query_is_hybrid_true_with_vector(): void
    {
        $q = new SearchQuery(
            indexName: 'test',
            vector: [0.1, 0.2, 0.3],
            vectorField: 'embedding',
        );
        $this->assertTrue($q->isHybrid);
    }

    #[Test]
    public function search_result_pagination_properties(): void
    {
        $result = new SearchResult(
            hits: [new SearchHit(id: '1', score: 1.0, document: [])],
            total: 50,
            page: 2,
            perPage: 10,
        );

        $this->assertSame(5, $result->lastPage);
        $this->assertTrue($result->hasMore);
        $this->assertSame(1, $result->count);
        $this->assertFalse($result->isEmpty);
    }

    #[Test]
    public function search_result_last_page_on_boundary(): void
    {
        $result = new SearchResult(hits: [], total: 30, page: 3, perPage: 10);
        $this->assertSame(3, $result->lastPage);
        $this->assertFalse($result->hasMore);
    }

    #[Test]
    public function search_result_empty(): void
    {
        $result = new SearchResult(hits: [], total: 0, page: 1, perPage: 10);
        $this->assertTrue($result->isEmpty);
        $this->assertSame(0, $result->count);
        $this->assertSame(1, $result->lastPage);
    }

    #[Test]
    public function facet_count_property(): void
    {
        $facet = new Facet(field: 'category', values: [
            'electronics' => 15,
            'clothing'    => 10,
            'books'       => 5,
        ]);

        $this->assertSame(3, $facet->count);
        $this->assertSame('category', $facet->field);
    }

    #[Test]
    public function search_hit_properties(): void
    {
        $hit = new SearchHit(
            id: '42',
            score: 0.95,
            document: ['name' => 'Test', 'price' => 10],
            highlights: ['name' => '<em>Test</em>'],
        );

        $this->assertSame('42', $hit->id);
        $this->assertSame(0.95, $hit->score);
        $this->assertSame('Test', $hit->document['name']);
        $this->assertSame('<em>Test</em>', $hit->highlights['name']);
    }
}
