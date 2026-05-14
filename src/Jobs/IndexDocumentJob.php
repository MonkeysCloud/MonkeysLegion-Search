<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Jobs;

use MonkeysLegion\Search\SearchManager;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Queue job for indexing a single document asynchronously.
 *
 * Implements DispatchableJobInterface when monkeyslegion-queue
 * is installed, otherwise can be executed synchronously.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class IndexDocumentJob
{
    public function __construct(
        private readonly string $indexName,
        private readonly string $documentId,
        /** @var array<string, mixed> */
        private readonly array $document,
        private readonly ?string $engine = null,
    ) {}

    /**
     * Execute the job — index the document.
     */
    public function handle(): void
    {
        $manager = SearchManagerResolver::resolve();

        $manager->indexDocument(
            $this->indexName,
            $this->documentId,
            $this->document,
            $this->engine,
        );
    }
}
