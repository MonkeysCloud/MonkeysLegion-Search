<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit\Engines;

use MonkeysLegion\Search\Dto\{IndexConfig, SearchQuery};
use MonkeysLegion\Search\Engines\NullEngine;
use MonkeysLegion\Search\Enum\SortDirection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the NullEngine.
 */
final class NullEngineTest extends TestCase
{
    private NullEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new NullEngine();
    }

    #[Test]
    public function create_and_check_index_exists(): void
    {
        $config = new IndexConfig(name: 'products', primaryKey: 'id');
        $this->engine->createIndex($config);
        self::assertTrue($this->engine->indexExists('products'));
        self::assertFalse($this->engine->indexExists('nonexistent'));
    }

    #[Test]
    public function index_and_retrieve_document(): void
    {
        $this->engine->createIndex(new IndexConfig('products'));
        $this->engine->index('products', '1', ['id' => 1, 'name' => 'Widget']);

        $results = $this->engine->search(new SearchQuery(
            indexName: 'products',
            term: 'Widget',
        ));

        self::assertSame(1, $results->total);
        self::assertCount(1, $results->hits);
        self::assertSame('1', $results->hits[0]->id);
        self::assertSame('Widget', $results->hits[0]->document['name']);
    }

    #[Test]
    public function bulk_index_multiple_documents(): void
    {
        $this->engine->createIndex(new IndexConfig('users'));
        $count = $this->engine->bulkIndex('users', [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Charlie'],
        ]);

        self::assertSame(3, $count);

        $results = $this->engine->search(new SearchQuery(indexName: 'users', term: ''));
        self::assertSame(3, $results->total);
    }

    #[Test]
    public function delete_document(): void
    {
        $this->engine->createIndex(new IndexConfig('products'));
        $this->engine->index('products', '1', ['name' => 'Widget']);
        $this->engine->delete('products', '1');

        $results = $this->engine->search(new SearchQuery(indexName: 'products', term: ''));
        self::assertSame(0, $results->total);
    }

    #[Test]
    public function bulk_delete_documents(): void
    {
        $this->engine->createIndex(new IndexConfig('products'));
        $this->engine->bulkIndex('products', [
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'B'],
            ['id' => 3, 'name' => 'C'],
        ]);

        $deleted = $this->engine->bulkDelete('products', ['1', '2']);
        self::assertSame(2, $deleted);

        $results = $this->engine->search(new SearchQuery(indexName: 'products', term: ''));
        self::assertSame(1, $results->total);
    }

    #[Test]
    public function search_with_filters(): void
    {
        $this->engine->createIndex(new IndexConfig('users'));
        $this->engine->bulkIndex('users', [
            ['id' => 1, 'name' => 'Alice', 'active' => true],
            ['id' => 2, 'name' => 'Bob', 'active' => false],
            ['id' => 3, 'name' => 'Charlie', 'active' => true],
        ]);

        $results = $this->engine->search(new SearchQuery(
            indexName: 'users',
            term: '',
            filters: [['field' => 'active', 'operator' => '=', 'value' => true]],
        ));

        self::assertSame(2, $results->total);
    }

    #[Test]
    public function search_with_sorting(): void
    {
        $this->engine->createIndex(new IndexConfig('users'));
        $this->engine->bulkIndex('users', [
            ['id' => 3, 'name' => 'Charlie'],
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);

        $results = $this->engine->search(new SearchQuery(
            indexName: 'users',
            term: '',
            sorts: [['field' => 'name', 'direction' => SortDirection::Asc]],
        ));

        self::assertSame('Alice', $results->hits[0]->document['name']);
        self::assertSame('Bob', $results->hits[1]->document['name']);
        self::assertSame('Charlie', $results->hits[2]->document['name']);
    }

    #[Test]
    public function search_with_pagination(): void
    {
        $this->engine->createIndex(new IndexConfig('users'));
        for ($i = 1; $i <= 25; $i++) {
            $this->engine->index('users', (string) $i, ['id' => $i, 'name' => "User{$i}"]);
        }

        $results = $this->engine->search(new SearchQuery(
            indexName: 'users',
            term: '',
            page: 2,
            perPage: 10,
        ));

        self::assertSame(25, $results->total);
        self::assertSame(2, $results->page);
        self::assertSame(10, $results->perPage);
        self::assertCount(10, $results->hits);
        self::assertSame(11, $results->hits[0]->document['id']);
    }

    #[Test]
    public function search_with_facets(): void
    {
        $this->engine->createIndex(new IndexConfig('products'));
        $this->engine->bulkIndex('products', [
            ['id' => 1, 'name' => 'A', 'category' => 'electronics'],
            ['id' => 2, 'name' => 'B', 'category' => 'electronics'],
            ['id' => 3, 'name' => 'C', 'category' => 'books'],
        ]);

        $results = $this->engine->search(new SearchQuery(
            indexName: 'products',
            term: '',
            facets: ['category'],
        ));

        self::assertCount(1, $results->facets);
        self::assertSame('category', $results->facets[0]->field);
        self::assertSame(2, $results->facets[0]->values['electronics']);
        self::assertSame(1, $results->facets[0]->values['books']);
    }

    #[Test]
    public function ping_returns_true(): void
    {
        self::assertTrue($this->engine->ping());
    }

    #[Test]
    public function info_returns_engine_details(): void
    {
        $info = $this->engine->info();
        self::assertSame('null', $info['engine']);
    }

    #[Test]
    public function delete_index(): void
    {
        $this->engine->createIndex(new IndexConfig('products'));
        $this->engine->index('products', '1', ['name' => 'Widget']);
        $this->engine->deleteIndex('products');

        self::assertFalse($this->engine->indexExists('products'));
    }

    #[Test]
    public function suggest_returns_matching_documents(): void
    {
        $this->engine->createIndex(new IndexConfig('products'));
        $this->engine->bulkIndex('products', [
            ['id' => 1, 'name' => 'Apple Watch'],
            ['id' => 2, 'name' => 'Apple iPhone'],
            ['id' => 3, 'name' => 'Samsung Galaxy'],
        ]);

        $suggestions = $this->engine->suggest('products', 'App', 5);
        self::assertCount(2, $suggestions);
    }
}
