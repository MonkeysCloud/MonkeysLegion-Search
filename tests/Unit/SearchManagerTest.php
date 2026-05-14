<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit;

use MonkeysLegion\Search\Dto\IndexConfig;
use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Engine\NullEngine;
use MonkeysLegion\Search\Enum\EngineDriver;
use MonkeysLegion\Search\Exceptions\SearchException;
use MonkeysLegion\Search\Query\Builder;
use MonkeysLegion\Search\SearchManager;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchManager::class)]
final class SearchManagerTest extends TestCase
{
    #[Test]
    public function default_driver_is_null_with_empty_config(): void
    {
        $mgr = new SearchManager();
        $this->assertSame(EngineDriver::Null, $mgr->defaultDriver);
    }

    #[Test]
    public function engine_returns_null_engine_by_default(): void
    {
        $mgr = new SearchManager(config: [
            'default' => 'default',
            'engines' => [
                'default' => ['driver' => 'null'],
            ],
        ]);

        $engine = $mgr->engine();
        $this->assertInstanceOf(NullEngine::class, $engine);
    }

    #[Test]
    public function engine_throws_on_unknown_connection(): void
    {
        $mgr = new SearchManager();

        $this->expectException(SearchException::class);
        $this->expectExceptionMessage('Unknown search engine connection: nonexistent');
        $mgr->engine('nonexistent');
    }

    #[Test]
    public function index_returns_builder(): void
    {
        $mgr = new SearchManager(config: [
            'default' => 'default',
            'engines' => [
                'default' => ['driver' => 'null'],
            ],
        ]);

        $builder = $mgr->index('products');
        $this->assertInstanceOf(Builder::class, $builder);
    }

    #[Test]
    public function register_engine_allows_custom_engines(): void
    {
        $mgr = new SearchManager();
        $custom = new NullEngine();
        $mgr->registerEngine('custom', $custom);

        $this->assertSame($custom, $mgr->engine('custom'));
    }

    #[Test]
    public function ping_delegates_to_engine(): void
    {
        $mgr = new SearchManager(config: [
            'default' => 'test',
            'engines' => [
                'test' => ['driver' => 'null'],
            ],
        ]);

        $this->assertTrue($mgr->ping());
    }

    #[Test]
    public function info_delegates_to_engine(): void
    {
        $mgr = new SearchManager(config: [
            'default' => 'test',
            'engines' => [
                'test' => ['driver' => 'null'],
            ],
        ]);

        $info = $mgr->info();
        $this->assertSame('null', $info['engine']);
    }

    #[Test]
    public function index_document_and_delete_document(): void
    {
        $mgr = new SearchManager(config: [
            'default' => 'test',
            'engines' => [
                'test' => ['driver' => 'null'],
            ],
        ]);

        // Create index first
        $mgr->engine()->createIndex(new IndexConfig(name: 'products'));

        $mgr->indexDocument('products', '1', ['name' => 'Test']);

        $result = $mgr->index('products')->query('Test')->get();
        $this->assertSame(1, $result->total);

        $mgr->deleteDocument('products', '1');

        $result = $mgr->index('products')->get();
        $this->assertSame(0, $result->total);
    }

    #[Test]
    public function multiple_engines_are_independent(): void
    {
        $mgr = new SearchManager(config: [
            'default' => 'primary',
            'engines' => [
                'primary'   => ['driver' => 'null'],
                'secondary' => ['driver' => 'null'],
            ],
        ]);

        $primary = $mgr->engine('primary');
        $secondary = $mgr->engine('secondary');

        $this->assertNotSame($primary, $secondary);
    }

    #[Test]
    public function engine_instances_are_cached(): void
    {
        $mgr = new SearchManager(config: [
            'default' => 'test',
            'engines' => [
                'test' => ['driver' => 'null'],
            ],
        ]);

        $first = $mgr->engine('test');
        $second = $mgr->engine('test');
        $this->assertSame($first, $second);
    }
}
