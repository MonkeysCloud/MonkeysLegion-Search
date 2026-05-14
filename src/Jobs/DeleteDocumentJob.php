<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Jobs;

use MonkeysLegion\Search\SearchManager;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Queue job for deleting a document from the search index asynchronously.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class DeleteDocumentJob
{
    public function __construct(
        private readonly string $indexName,
        private readonly string $documentId,
        private readonly ?string $engine = null,
    ) {}

    /**
     * Execute the job — delete the document.
     */
    public function handle(): void
    {
        $manager = SearchManagerResolver::resolve();

        $manager->deleteDocument(
            $this->indexName,
            $this->documentId,
            $this->engine,
        );
    }
}
