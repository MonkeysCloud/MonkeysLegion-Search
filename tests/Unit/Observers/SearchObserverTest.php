<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit\Observers;

use MonkeysLegion\Search\Attributes\Searchable;
use MonkeysLegion\Search\Attributes\SearchField;
use MonkeysLegion\Search\Dto\IndexConfig;
use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Engine\NullEngine;
use MonkeysLegion\Search\Observers\SearchObserver;
use MonkeysLegion\Search\SearchManager;
use MonkeysLegion\Search\Traits\SearchableTrait;
use PHPUnit\Framework\TestCase;

/**
 * @copyright 2026 MonkeysCloud Team
 */
final class SearchObserverTest extends TestCase
{
    private NullEngine $engine;
    private SearchObserver $observer;

    protected function setUp(): void
    {
        $this->engine = new NullEngine();
        $this->engine->createIndex(new IndexConfig(name: 'obs_products'));

        $manager = new SearchManager();
        $manager->registerEngine('default', $this->engine);

        SearchObserver::setSearchManager($manager);
        ObservableProduct::setSearchManager($manager);

        $this->observer = new SearchObserver();
    }

    public function testCreatedIndexesEntity(): void
    {
        $product = new ObservableProduct();
        $product->id = '1';
        $product->name = 'New Product';

        $this->observer->created($product);

        $result = $this->engine->search(new SearchQuery(indexName: 'obs_products', term: 'New'));
        self::assertEquals(1, $result->total);
    }

    public function testUpdatedReIndexesEntity(): void
    {
        $product = new ObservableProduct();
        $product->id = '1';
        $product->name = 'Original';

        $this->observer->created($product);

        $product->name = 'Updated';
        $this->observer->updated($product);

        $result = $this->engine->search(new SearchQuery(indexName: 'obs_products', term: 'Updated'));
        self::assertEquals(1, $result->total);
    }

    public function testDeletedRemovesFromIndex(): void
    {
        $product = new ObservableProduct();
        $product->id = '1';
        $product->name = 'To Delete';

        $this->observer->created($product);
        $this->observer->deleted($product);

        $result = $this->engine->search(new SearchQuery(indexName: 'obs_products', term: 'To Delete'));
        self::assertEquals(0, $result->total);
    }

    public function testShouldBeSearchableFalseRemovesDoc(): void
    {
        $product = new ConditionalObsProduct();
        $product->id = '1';
        $product->name = 'Draft Product';
        $product->status = 'published';
        ConditionalObsProduct::setSearchManager(new SearchManager());

        $manager = new SearchManager();
        $manager->registerEngine('default', $this->engine);
        SearchObserver::setSearchManager($manager);
        ConditionalObsProduct::setSearchManager($manager);

        $this->observer->created($product);

        // Now unpublish — should be removed
        $product->status = 'draft';
        $this->observer->updated($product);

        $result = $this->engine->search(new SearchQuery(indexName: 'obs_conditionals', term: 'Draft'));
        self::assertEquals(0, $result->total);
    }

    public function testRestoredReIndexes(): void
    {
        $product = new ObservableProduct();
        $product->id = '1';
        $product->name = 'Restored';

        $this->observer->restored($product);

        $result = $this->engine->search(new SearchQuery(indexName: 'obs_products', term: 'Restored'));
        self::assertEquals(1, $result->total);
    }

    public function testNonSearchableEntityIsIgnored(): void
    {
        $nonSearchable = new \stdClass();
        $nonSearchable->id = '1';

        $this->observer->created($nonSearchable);

        // Should not throw, just silently ignore
        self::assertTrue(true);
    }
}

// ── Test Fixtures ──────────────────────────────────────────────────

#[Searchable(index: 'obs_products', autoSync: true)]
class ObservableProduct
{
    use SearchableTrait;

    #[SearchField(searchable: true)]
    public string $id = '';

    #[SearchField(searchable: true)]
    public string $name = '';
}

#[Searchable(index: 'obs_conditionals', autoSync: true)]
class ConditionalObsProduct
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
