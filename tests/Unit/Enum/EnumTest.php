<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit\Enum;

use MonkeysLegion\Search\Enum\EngineDriver;
use MonkeysLegion\Search\Enum\FieldType;
use MonkeysLegion\Search\Enum\SortDirection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EngineDriver::class)]
#[CoversClass(FieldType::class)]
#[CoversClass(SortDirection::class)]
final class EnumTest extends TestCase
{
    #[Test]
    public function engine_driver_labels(): void
    {
        $this->assertSame('Meilisearch', EngineDriver::Meilisearch->label());
        $this->assertSame('Apache Solr', EngineDriver::Solr->label());
        $this->assertSame('Null (Testing)', EngineDriver::Null->label());
    }

    #[Test]
    public function engine_driver_default_ports(): void
    {
        $this->assertSame(7700, EngineDriver::Meilisearch->defaultPort());
        $this->assertSame(8108, EngineDriver::Typesense->defaultPort());
        $this->assertSame(9200, EngineDriver::OpenSearch->defaultPort());
        $this->assertSame(9200, EngineDriver::Elasticsearch->defaultPort());
        $this->assertSame(8983, EngineDriver::Solr->defaultPort());
        $this->assertSame(0, EngineDriver::Null->defaultPort());
    }

    #[Test]
    public function engine_driver_vector_support(): void
    {
        $this->assertTrue(EngineDriver::OpenSearch->supportsVectorSearch());
        $this->assertTrue(EngineDriver::Elasticsearch->supportsVectorSearch());
        $this->assertTrue(EngineDriver::Typesense->supportsVectorSearch());
        $this->assertFalse(EngineDriver::Meilisearch->supportsVectorSearch());
        $this->assertFalse(EngineDriver::Solr->supportsVectorSearch());
        $this->assertFalse(EngineDriver::Null->supportsVectorSearch());
    }

    #[Test]
    public function field_type_is_full_text(): void
    {
        $this->assertTrue(FieldType::Text->isFullText());
        $this->assertTrue(FieldType::String->isFullText());
        $this->assertFalse(FieldType::Integer->isFullText());
        $this->assertFalse(FieldType::Boolean->isFullText());
    }

    #[Test]
    public function field_type_is_numeric(): void
    {
        $this->assertTrue(FieldType::Integer->isNumeric());
        $this->assertTrue(FieldType::Float->isNumeric());
        $this->assertFalse(FieldType::Text->isNumeric());
        $this->assertFalse(FieldType::Date->isNumeric());
    }

    #[Test]
    public function sort_direction_values(): void
    {
        $this->assertSame('asc', SortDirection::Asc->value);
        $this->assertSame('desc', SortDirection::Desc->value);
    }
}
