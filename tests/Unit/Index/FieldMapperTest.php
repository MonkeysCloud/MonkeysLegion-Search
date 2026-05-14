<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Tests\Unit\Index;

use MonkeysLegion\Search\Enum\FieldType;
use MonkeysLegion\Search\Index\FieldMapper;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldMapper::class)]
final class FieldMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, FieldType}>
     */
    public static function typeMapProvider(): iterable
    {
        yield 'string'         => ['string', FieldType::String];
        yield 'char'           => ['char', FieldType::String];
        yield 'uuid'           => ['uuid', FieldType::String];
        yield 'text'           => ['text', FieldType::Text];
        yield 'mediumText'     => ['mediumText', FieldType::Text];
        yield 'longText'       => ['longText', FieldType::Text];
        yield 'integer'        => ['integer', FieldType::Integer];
        yield 'int'            => ['int', FieldType::Integer];
        yield 'bigInt'         => ['bigInt', FieldType::Integer];
        yield 'unsignedBigInt' => ['unsignedBigInt', FieldType::Integer];
        yield 'decimal'        => ['decimal', FieldType::Float];
        yield 'float'          => ['float', FieldType::Float];
        yield 'boolean'        => ['boolean', FieldType::Boolean];
        yield 'bool'           => ['bool', FieldType::Boolean];
        yield 'date'           => ['date', FieldType::Date];
        yield 'datetime'       => ['datetime', FieldType::Date];
        yield 'timestamp'      => ['timestamp', FieldType::Date];
        yield 'json'           => ['json', FieldType::Json];
        yield 'point'          => ['point', FieldType::Geo];
        yield 'vector'         => ['vector', FieldType::Vector];
        yield 'unknown'        => ['custom_type', FieldType::Text];
    }

    #[Test]
    #[DataProvider('typeMapProvider')]
    public function to_search_type_maps_correctly(string $entityType, FieldType $expected): void
    {
        $this->assertSame($expected, FieldMapper::toSearchType($entityType));
    }
}
