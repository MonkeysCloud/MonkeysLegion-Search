<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Enum;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Backed enum of all supported search engine drivers.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
enum EngineDriver: string
{
    case Meilisearch   = 'meilisearch';
    case Typesense     = 'typesense';
    case OpenSearch    = 'opensearch';
    case Elasticsearch = 'elasticsearch';
    case Solr          = 'solr';
    case Null          = 'null';

    /**
     * Human-readable label for dashboard/CLI display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Meilisearch   => 'Meilisearch',
            self::Typesense     => 'Typesense',
            self::OpenSearch    => 'OpenSearch',
            self::Elasticsearch => 'Elasticsearch',
            self::Solr          => 'Apache Solr',
            self::Null          => 'Null (Testing)',
        };
    }

    /**
     * Default port for each engine.
     */
    public function defaultPort(): int
    {
        return match ($this) {
            self::Meilisearch   => 7700,
            self::Typesense     => 8108,
            self::OpenSearch    => 9200,
            self::Elasticsearch => 9200,
            self::Solr          => 8983,
            self::Null          => 0,
        };
    }

    /**
     * Whether this driver supports native kNN vector search.
     */
    public function supportsVectorSearch(): bool
    {
        return match ($this) {
            self::OpenSearch, self::Elasticsearch, self::Typesense => true,
            default => false,
        };
    }
}
