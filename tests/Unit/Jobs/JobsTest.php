<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit\Jobs;

use MonkeysLegion\Search\Dto\IndexConfig;
use MonkeysLegion\Search\Dto\SearchQuery;
use MonkeysLegion\Search\Engine\NullEngine;
use MonkeysLegion\Search\Jobs\BulkIndexJob;
use MonkeysLegion\Search\Jobs\DeleteDocumentJob;
use MonkeysLegion\Search\Jobs\IndexDocumentJob;
use MonkeysLegion\Search\Jobs\SearchManagerResolver;
use MonkeysLegion\Search\SearchManager;
use PHPUnit\Framework\TestCase;

/**
 * @copyright 2026 MonkeysCloud Team
 */
final class JobsTest extends TestCase
{
    private NullEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new NullEngine();
        $this->engine->createIndex(new IndexConfig(name: 'job_test'));

        $manager = new SearchManager();
        $manager->registerEngine('default', $this->engine);
        SearchManagerResolver::set($manager);
    }

    protected function tearDown(): void
    {
        SearchManagerResolver::clear();
    }

    public function testIndexDocumentJob(): void
    {
        $job = new IndexDocumentJob('job_test', '1', ['id' => '1', 'name' => 'Test']);
        $job->handle();

        $result = $this->engine->search(new SearchQuery(indexName: 'job_test', term: 'Test'));
        self::assertEquals(1, $result->total);
    }

    public function testDeleteDocumentJob(): void
    {
        $this->engine->index('job_test', '1', ['id' => '1', 'name' => 'To Delete']);

        $job = new DeleteDocumentJob('job_test', '1');
        $job->handle();

        $result = $this->engine->search(new SearchQuery(indexName: 'job_test', term: 'To Delete'));
        self::assertEquals(0, $result->total);
    }

    public function testBulkIndexJob(): void
    {
        $job = new BulkIndexJob('job_test', [
            ['id' => '1', 'name' => 'Bulk 1'],
            ['id' => '2', 'name' => 'Bulk 2'],
            ['id' => '3', 'name' => 'Bulk 3'],
        ]);
        $job->handle();

        $result = $this->engine->search(new SearchQuery(indexName: 'job_test'));
        self::assertEquals(3, $result->total);
    }

    public function testSearchManagerResolverThrowsWhenUnset(): void
    {
        SearchManagerResolver::clear();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SearchManager not registered');

        SearchManagerResolver::resolve();
    }
}
