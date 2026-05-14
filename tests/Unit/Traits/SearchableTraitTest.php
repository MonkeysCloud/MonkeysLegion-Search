<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit\Traits;

use MonkeysLegion\Search\Attributes\Searchable;
use MonkeysLegion\Search\Attributes\SearchField;
use MonkeysLegion\Search\Engine\NullEngine;
use MonkeysLegion\Search\SearchManager;
use MonkeysLegion\Search\Traits\SearchableTrait;
use PHPUnit\Framework\TestCase;

/**
 * @copyright 2026 MonkeysCloud Team
 */
final class SearchableTraitTest extends TestCase
{
    private SearchManager $manager;
    private NullEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new NullEngine();
        $this->manager = new SearchManager(['default' => 'test', 'engines' => ['test' => ['driver' => 'null']]]);
        $this->manager->registerEngine('default', $this->engine);
        TestProduct::setSearchManager($this->manager);
    }

    public function testToSearchableArrayReturnsPublicSearchFields(): void
    {
        $product = new TestProduct();
        $product->id = '1';
        $product->name = 'Wireless Headphones';
        $product->price = 99.99;

        $data = $product->toSearchableArray();

        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('name', $data);
        self::assertEquals('Wireless Headphones', $data['name']);
    }

    public function testShouldBeSearchableDefaultsTrue(): void
    {
        $product = new TestProduct();
        self::assertTrue($product->shouldBeSearchable());
    }

    public function testConditionalSearchable(): void
    {
        $draft = new TestDraftProduct();
        $draft->status = 'draft';
        self::assertFalse($draft->shouldBeSearchable());

        $draft->status = 'published';
        self::assertTrue($draft->shouldBeSearchable());
    }

    public function testGetSearchKey(): void
    {
        $product = new TestProduct();
        $product->id = '42';
        self::assertEquals('42', $product->getSearchKey());
    }

    public function testGetSearchIndex(): void
    {
        $product = new TestProduct();
        self::assertEquals('test_products', $product->getSearchIndex());
    }

    public function testSearchable(): void
    {
        $product = new TestProduct();
        $product->id = '1';
        $product->name = 'Test Product';
        $product->price = 10.0;

        $this->engine->createIndex(new \MonkeysLegion\Search\Dto\IndexConfig(name: 'test_products'));

        $product->searchable();

        // Verify it's in the engine
        $result = $this->engine->search(new \MonkeysLegion\Search\Dto\SearchQuery(indexName: 'test_products', term: 'Test'));
        self::assertGreaterThan(0, $result->total);
    }

    public function testUnsearchable(): void
    {
        $product = new TestProduct();
        $product->id = '1';
        $product->name = 'Test Product';
        $product->price = 10.0;

        $this->engine->createIndex(new \MonkeysLegion\Search\Dto\IndexConfig(name: 'test_products'));
        $product->searchable();
        $product->unsearchable();

        $result = $this->engine->search(new \MonkeysLegion\Search\Dto\SearchQuery(indexName: 'test_products', term: 'Test'));
        self::assertEquals(0, $result->total);
    }

    public function testStaticSearch(): void
    {
        $this->engine->createIndex(new \MonkeysLegion\Search\Dto\IndexConfig(name: 'test_products'));
        $this->engine->index('test_products', '1', ['id' => '1', 'name' => 'Widget']);

        $builder = TestProduct::search('Widget');
        $result = $builder->get();

        self::assertEquals(1, $result->total);
    }

    public function testMakeSearchable(): void
    {
        $this->engine->createIndex(new \MonkeysLegion\Search\Dto\IndexConfig(name: 'test_products'));

        $p1 = new TestProduct();
        $p1->id = '1';
        $p1->name = 'Product 1';
        $p1->price = 10.0;

        $p2 = new TestProduct();
        $p2->id = '2';
        $p2->name = 'Product 2';
        $p2->price = 20.0;

        $count = TestProduct::makeSearchable([$p1, $p2]);
        self::assertEquals(2, $count);
    }

    public function testMakeUnsearchable(): void
    {
        $this->engine->createIndex(new \MonkeysLegion\Search\Dto\IndexConfig(name: 'test_products'));

        $p1 = new TestProduct();
        $p1->id = '1';
        $p1->name = 'Product 1';
        $p1->price = 10.0;

        $p1->searchable();
        $count = TestProduct::makeUnsearchable([$p1]);
        self::assertEquals(1, $count);
    }
}

// ── Test Fixtures ──────────────────────────────────────────────────

#[Searchable(index: 'test_products')]
class TestProduct
{
    use SearchableTrait;

    #[SearchField(searchable: true)]
    public string $id = '';

    #[SearchField(searchable: true, filterable: true)]
    public string $name = '';

    #[SearchField(sortable: true)]
    public float $price = 0.0;
}

#[Searchable(index: 'test_draft_products')]
class TestDraftProduct
{
    use SearchableTrait;

    public string $id = '';
    public string $name = '';
    public string $status = 'draft';

    public function shouldBeSearchable(): bool
    {
        return $this->status === 'published';
    }
}
