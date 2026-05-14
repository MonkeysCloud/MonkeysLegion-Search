<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Jobs;

use MonkeysLegion\Search\SearchManager;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Queue job for bulk-indexing multiple documents asynchronously.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class BulkIndexJob
{
    /**
     * @param string                      $indexName  Target index.
     * @param array<array<string, mixed>> $documents  Documents to index.
     * @param string|null                 $engine     Engine connection.
     */
    public function __construct(
        private readonly string $indexName,
        private readonly array $documents,
        private readonly ?string $engine = null,
    ) {}

    /**
     * Execute the job — bulk index documents.
     */
    public function handle(): void
    {
        $manager = SearchManagerResolver::resolve();

        $manager->engine($this->engine)->bulkIndex(
            $this->indexName,
            $this->documents,
        );
    }
}
