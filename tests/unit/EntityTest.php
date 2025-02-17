<?php

declare(strict_types=1);

namespace Tests;

use DateTime;
use PHPUnit\Framework\TestCase;
use Uakari\Attributes\PrimaryKey;
use Uakari\Attributes\SqlDefault;
use Uakari\Entity;

final class EntityTest extends TestCase
{
    private Entity $example;

    public function testGetPrimaryKeyName(): void
    {
        $this->assertSame(
            "id",
            $this->example::getPrimaryKeyName(),
        );
    }

    public function testGetSchemaName(): void
    {
        $this->assertSame(
            "test",
            $this->example::getSchemaName(),
        );
    }

    public function testCreateNewEntity(): void
    {
        $entity = $this->example::create(value: "foo");
        $this->assertInstanceOf(($this->example)::class, $entity);
    }

    public function testFailToCreateWithoutValueThrows(): void
    {
        $this->expectException(\ValueError::class);
        $entity = $this->example::create();
    }

    public function testSetPropertyStringFromStringIntFloat(): void
    {
        $this->example->setProperty('value', "test value", null);
        $this->assertSame(
            "test value",
            $this->example->value
        );

        $this->example->setProperty('value', 1, null);
        $this->assertSame(
            "1",
            $this->example->value
        );

        /*
         * We don't really do anything special with floats, they are handled
         * as if (string)$value was passed.
         */
        $this->example->setProperty('value', 5.00, null);
        $this->assertSame(
            "5",
            $this->example->value
        );
    }

    public function testSetPropertyNotNullableFromNullThrows(): void
    {
        $this->expectException(\ValueError::class);
        $this->example->setProperty('value', null, null);
    }

    public function testSetPropertyDateTimeFromStringIntFloat(): void
    {
        $this->example->setProperty('dateTime', "1944-06-04 00:15", null);
        $this->assertSame(
            "-807147900",
            $this->example->dateTime->format('U')
        );
        $this->example->setProperty('dateTime', -14182940, null);
        $this->assertSame(
            "1969-07-20T20:17:40+00:00",
            $this->example->dateTime->format('c')
        );
        $this->example->setProperty('dateTime', -14182940.123456, null);
        $this->assertSame(
            "123456",
            $this->example->dateTime->format('u')
        );
    }

    public function testSetPropertyArrayFromString(): void
    {
        $jsonString = json_encode(['foo' => 'bar']);
        $this->example->setProperty('simpleArray', $jsonString, null);
        $this->assertSame(
            'bar',
            $this->example->simpleArray['foo'],
        );

        $complex = ['foo', 1, [ 'bar' => 'baz' ]];
        $jsonString = json_encode($complex);
        $this->example->setProperty('simpleArray', $jsonString, null);
        $this->assertSame(
            $complex,
            $this->example->simpleArray,
        );
    }

    public function testSetPropertyArrayFromInvalidStringThrows(): void
    {
        $this->expectException(\JsonException::class);
        $this->example->setProperty('simpleArray', '[', null);
    }

    public function testCreateFromPdoRow(): void
    {
        $row = $this->createMock(\PDOStatement::class);
        $row->expects($this->once())->method('columnCount')->willReturn(5);
        $row->expects($this->exactly(5))->method('getColumnMeta')->willReturnMap(
            [
                [0, ['name' => 'id', 'sqlite:decl_type' => 'int']],
                [1, ['name' => 'value', 'sqlite:decl_type' => 'string']],
                [2, ['name' => 'date_time', 'sqlite:decl_type' => 'string']],
                [3, ['name' => 'simple_array', 'sqlite:decl_type' => 'json']],
                [4, ['name' => 'has_default', 'sqlite:decl_type' => 'string']],
            ],
        );

        $entity = $this->example::fromPdoRow($row, [
            3,
            'foo',
            '2025-02-16T15:32:00Z',
            \json_encode([1, 2, 3]),
            'bar',
        ]);

        $this->assertSame(3, $entity->id);
        $this->assertSame('foo', $entity->value);
        $this->assertSame("1739719920", $entity->dateTime->format('U'));
        $this->assertSame([1,2,3], $entity->simpleArray);
        $this->assertSame("bar", $entity->hasDefault);
    }

    public function testCreateFromPdoRowMissingValueThrows(): void
    {
        $row = $this->createMock(\PDOStatement::class);
        $row->expects($this->once())->method('columnCount')->willReturn(1);
        $row->expects($this->once())->method('getColumnMeta')->willReturnMap(
            [
                [0, ['name' => 'id', 'sqlite:decl_type' => 'int']],
            ],
        );

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage("'value'");
        $entity = $this->example::fromPdoRow($row, [4]);
    }

    protected function setUp(): void
    {
        date_default_timezone_set('UTC');
        $this->example = new class () extends Entity {
            public static ?string $schemaName = 'test';

            #[PrimaryKey]
            public ?int $id;

            public string $value;
            public ?string $isNullable = null;
            public ?DateTime $dateTime = null;
            public ?array $simpleArray = null;

            #[SqlDefault("bar")]
            public string $hasDefault;
        };
    }

    protected function tearDown(): void
    {
    }
}
