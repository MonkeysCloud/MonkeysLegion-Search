<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit\Engine;

use MonkeysLegion\Search\Dto\IndexConfig;
use MonkeysLegion\Search\Dto\IndexFieldConfig;
use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Engine\NullEngine;
use MonkeysLegion\Search\Enum\FieldType;
use MonkeysLegion\Search\Enum\SortDirection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullEngine::class)]
final class NullEngineTest extends TestCase
{
    private NullEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new NullEngine();
    }

    #[Test]
    public function ping_returns_true(): void
    {
        $this->assertTrue($this->engine->ping());
    }

    #[Test]
    public function info_returns_engine_metadata(): void
    {
        $info = $this->engine->info();
        $this->assertSame('null', $info['engine']);
        $this->assertSame('1.0.0', $info['version']);
    }

    #[Test]
    public function create_and_check_index_exists(): void
    {
        $config = new IndexConfig(name: 'test_index');
        $this->engine->createIndex($config);

        $this->assertTrue($this->engine->indexExists('test_index'));
        $this->assertFalse($this->engine->indexExists('nonexistent'));
    }

    #[Test]
    public function delete_index_removes_it(): void
    {
        $config = new IndexConfig(name: 'test_index');
        $this->engine->createIndex($config);
        $this->assertTrue($this->engine->indexExists('test_index'));

        $this->engine->deleteIndex('test_index');
        $this->assertFalse($this->engine->indexExists('test_index'));
    }

    #[Test]
    public function index_and_search_single_document(): void
    {
        $config = new IndexConfig(name: 'products');
        $this->engine->createIndex($config);

        $this->engine->index('products', '1', [
            'name'  => 'Wireless Headphones',
            'price' => 99.99,
        ]);

        $query = new SearchQuery(indexName: 'products', term: 'wireless');
        $result = $this->engine->search($query);

        $this->assertSame(1, $result->total);
        $this->assertCount(1, $result->hits);
        $this->assertSame('1', $result->hits[0]->id);
    }

    #[Test]
    public function search_with_no_term_returns_all_documents(): void
    {
        $config = new IndexConfig(name: 'products');
        $this->engine->createIndex($config);

        $this->engine->index('products', '1', ['name' => 'Product A']);
        $this->engine->index('products', '2', ['name' => 'Product B']);
        $this->engine->index('products', '3', ['name' => 'Product C']);

        $query = new SearchQuery(indexName: 'products', term: '');
        $result = $this->engine->search($query);

        $this->assertSame(3, $result->total);
    }

    #[Test]
    public function bulk_index_adds_multiple_documents(): void
    {
        $config = new IndexConfig(name: 'products');
        $this->engine->createIndex($config);

        $count = $this->engine->bulkIndex('products', [
            ['id' => '1', 'name' => 'Product A'],
            ['id' => '2', 'name' => 'Product B'],
        ]);

        $this->assertSame(2, $count);

        $result = $this->engine->search(new SearchQuery(indexName: 'products'));
        $this->assertSame(2, $result->total);
    }

    #[Test]
    public function delete_removes_document(): void
    {
        $config = new IndexConfig(name: 'products');
        $this->engine->createIndex($config);

        $this->engine->index('products', '1', ['name' => 'Product A']);
        $this->engine->index('products', '2', ['name' => 'Product B']);

        $this->engine->delete('products', '1');

        $result = $this->engine->search(new SearchQuery(indexName: 'products'));
        $this->assertSame(1, $result->total);
        $this->assertSame('2', $result->hits[0]->id);
    }

    #[Test]
    public function bulk_delete_removes_multiple_documents(): void
    {
        $config = new IndexConfig(name: 'products');
        $this->engine->createIndex($config);

        $this->engine->bulkIndex('products', [
            ['id' => '1', 'name' => 'A'],
            ['id' => '2', 'name' => 'B'],
            ['id' => '3', 'name' => 'C'],
        ]);

        $deleted = $this->engine->bulkDelete('products', ['1', '3']);
        $this->assertSame(2, $deleted);

        $result = $this->engine->search(new SearchQuery(indexName: 'products'));
        $this->assertSame(1, $result->total);
    }

    #[Test]
    public function search_respects_pagination(): void
    {
        $config = new IndexConfig(name: 'products');
        $this->engine->createIndex($config);

        for ($i = 1; $i <= 10; $i++) {
            $this->engine->index('products', (string) $i, ['name' => "Product {$i}"]);
        }

        $query = new SearchQuery(indexName: 'products', page: 2, perPage: 3);
        $result = $this->engine->search($query);

        $this->assertSame(10, $result->total);
        $this->assertCount(3, $result->hits);
        $this->assertSame(2, $result->page);
        $this->assertTrue($result->hasMore);
    }

    #[Test]
    public function update_settings_is_noop(): void
    {
        $config = new IndexConfig(name: 'test');
        $this->engine->createIndex($config);

        // Should not throw
        $this->engine->updateSettings('test', ['foo' => 'bar']);
        $this->assertTrue(true);
    }
}
