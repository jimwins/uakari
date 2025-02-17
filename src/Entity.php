<?php

namespace Uakari;

use Uakari\Attributes\AutoIncrement;
use Uakari\Attributes\Indexed;
use Uakari\Attributes\PrimaryKey;
use Uakari\Attributes\SqlDefault;
use Uakari\Attributes\SqlOnUpdate;
use Uakari\Attributes\SqlType;
use Uakari\Attributes\Unique;
use Uakari\Enums\DefaultConstant;

/**
 * The base Entity class.
 */
class Entity
{
    protected static ?string $schemaName = null;

    final public function __construct()
    {
    }

    /*
     * Get the name of the schema used for this Entity type. Can be configured
     * in the class by setting the protected $schemaName static property, or
     * is the snake_case version of the class name.
     */
    public static function getSchemaName(): string
    {
        return
            static::$schemaName ??
            self::camelCaseToSnakeCase((new \ReflectionClass(static::class))->getShortName());
    }

    public static function getPrimaryKeyName(): string
    {
        $properties =
            (new \ReflectionClass(static::class))
                ->getProperties(\ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            if ($property->getAttributes(PrimaryKey::class) !== []) {
                return $property->getName();
            }
        }

        throw new \ValueError("Entity does not have a primary key");
    }

    public static function create(mixed ...$args): static
    {
        $entity = new static();

        foreach ($args as $name => $arg) {
            $entity->{$name} = $arg;
        }

        /**
         * Make sure all public properties are initialized, except those that
         * are nullable, have default values from the SQL level, or are the
         * primary key.
         */
        $properties =
            (new \ReflectionClass(static::class))
                ->getProperties(\ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            if (
                !$property->isInitialized($entity) &&
                !($property->getType()?->allowsNull() ?? false) &&
                $property->getAttributes(SqlDefault::class) === [] &&
                $property->getAttributes(PrimaryKey::class) === []
            ) {
                $propertyName = $property->getName();
                throw new \ValueError("Property '{$propertyName}' not initialized");
            }
        }

        return $entity;
    }

    /**
     * @return list<array{name: string, 'decl_type': string}>
     */
    private static function getColumnsMeta(\PDOStatement $res): array
    {
        $columns = $res->columnCount();
        $columnsMeta = [];
        for ($i = 0; $i < $columns; $i++) {
            $meta = $res->getColumnMeta($i);
            if ($meta === false) {
                throw new \ValueError("Unable to get metadata for column {$i}");
            }
            /** @phpstan-ignore offsetAccess.notFound, cast.string */
            $meta['decl_type'] = (string)$meta['sqlite:decl_type'];
            $columnsMeta[] = $meta;
        }
        return $columnsMeta;
    }

    /* based on https://gist.github.com/carousel/1aacbea013d230768b3dec1a14ce5751 */
    private static function snakeCaseToCamelCase(string $snake_case): string
    {
        return \lcfirst(\str_replace('_', '', \ucwords($snake_case, '_')));
    }

    /* This is really camelCase or PascalCase to snake_case */
    private static function camelCaseToSnakeCase(string $camelCase): string
    {
        /* preg_replace() won't ever really return null, but just in case. */
        return \strtolower(\preg_replace('/(?<!^)[A-Z]/', '_$0', $camelCase) ?? $camelCase);
    }

    public static function fromPdoRow(\PDOStatement $res, mixed $columns): self
    {
        $entity = new static();

        if (!is_array($columns)) {
            throw new \ValueError("columns parameter must be an array of values");
        }

        $columnsMeta = self::getColumnsMeta($res);

        foreach ($columnsMeta as $column => $meta) {
            $propertyName = self::snakeCaseToCamelCase($meta['name']);
            /** @var null|int|float|string */
            $value = $columns[$column];
            $entity->setProperty($propertyName, $value, $meta);
        }

        /* Make sure all public properties are initialized. */
        $properties =
            (new \ReflectionClass(static::class))
                ->getProperties(\ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            if (
                !$property->isStatic() &&
                !$property->isInitialized($entity)
            ) {
                $propertyName = $property->getName();
                throw new \ValueError("Property '{$propertyName}' not initialized");
            }
        }

        return $entity;
    }

    /**
     * @param null|float|int|string $value
     * @param ?array{name: string, 'decl_type': string} $columnMeta
     */
    public function setProperty(string $propertyName, mixed $value, ?array $columnMeta): void
    {
        $propertyType = (new \ReflectionProperty(static::class, $propertyName))->getType();

        if (is_null($propertyType) || !($propertyType instanceof \ReflectionNamedType)) {
            $className = is_null($propertyType) ? 'null' : get_class($propertyType);
            throw new \ValueError("Unable to handle property with '{$className}' type");
        }

        if (is_null($value) && !$propertyType->allowsNull()) {
            throw new \ValueError("Property '{$propertyName}' is not nullable");
        }

        $this->{$propertyName} = match ($propertyType->getName()) {
            'DateTime', '?DateTime' => match (true) {
                is_null($value) => null,
                is_string($value) => new \DateTime($value),
                is_int($value) => \DateTime::createFromFormat("U", (string)$value),
                is_float($value) => \DateTime::createFromFormat("U.u", (string)$value),
            },
            'array', '?array' => match (true) {
                is_null($value) => null,
                is_string($value) =>
                    json_decode($value, flags: \JSON_THROW_ON_ERROR | \JSON_OBJECT_AS_ARRAY),
                default => throw new \ValueError("Unable to convert '$value' to array"),
            },
            default =>
                ($propertyType->isBuiltin() ? $value :
                    (is_null($value) ? $value :
                        ($propertyType->getName())::
                            fromPdo($columnMeta, $value))),
        };
    }


    /**
     * @return non-empty-list<non-falsy-string>
     */
    public static function getCreateTableStatement(\PDO $driver)
    {
        /* Note: this using ANSI SQL quoting style. */
        $schemaName = static::getSchemaName();

        /* We will create columns for all public properties. */
        $properties =
            (new \ReflectionClass(static::class))
                ->getProperties(\ReflectionProperty::IS_PUBLIC);

        $columns = [];
        $indexes = [];
        foreach ($properties as $property) {
            $columnName = self::camelCaseToSnakeCase($property->getName());
            $propertyType = $property->getType();
            if (is_null($propertyType) || !($propertyType instanceof \ReflectionNamedType)) {
                $className = is_null($propertyType) ? 'null' : get_class($propertyType);
                throw new \ValueError("Unable to handle parameter with '{$className}' type");
            }
            $columnType = match ($propertyType->getName()) {
                'int' => 'integer',
                'string' => 'string',
                'DateTime' => 'datetime',
                'array' => 'json',
                default => $property->getName(),
            };
            $columnConstraint = $propertyType->allowsNull() ? '' : 'NOT NULL';

            $attributes = $property->getAttributes();
            foreach ($attributes as $attribute) {
                switch ($attribute->getName()) {
                    case SqlType::class:
                        /** @var SqlType */
                        $instance = $attribute->newInstance();
                        $columnType = $instance->type;
                        break;
                    case SqlDefault::class:
                        /** @var SqlDefault */
                        $instance = $attribute->newInstance();
                        $default = $instance->default;
                        if ($default instanceof DefaultConstant) {
                            $default = $default->value;
                        } else {
                            $default = $driver->quote($default);
                        }
                        $columnConstraint .= " DEFAULT ({$default})";
                        break;
                    case PrimaryKey::class:
                        $columnConstraint .= " PRIMARY KEY";
                        break;
                    case AutoIncrement::class:
                        $columnConstraint .= " AUTOINCREMENT";
                        break;
                    case Unique::class:
                        $columnConstraint .= " UNIQUE";
                        break;
                    case Indexed::class:
                        $indexes[] = <<<SQL
                            CREATE INDEX "idx_{$columnName}" ON "{$schemaName}"("{$columnName}");
                        SQL;
                        break;
                }
            }

            $columns[] = "\"$columnName\" $columnType $columnConstraint";
        }
        $allColumns = join(', ', $columns);

        var_dump([
            "CREATE TABLE \"{$schemaName}\" ($allColumns)",
            ...$indexes
        ]);
        return [
            "CREATE TABLE \"{$schemaName}\" ($allColumns)",
            ...$indexes
        ];
    }

    /**
     * @return array{non-falsy-string, list<mixed>}
     */
    public function getInsertStatementAndValues(): array
    {
        /* Note: this using ANSI SQL quoting style. */
        $schemaName = static::getSchemaName();

        $properties =
            (new \ReflectionClass($this))
                ->getProperties(\ReflectionProperty::IS_PUBLIC);

        $columns = [];
        $values = [];
        foreach ($properties as $property) {
            $columnName = self::camelCaseToSnakeCase($property->getName());
            if (!$property->isStatic() && $property->isInitialized($this)) {
                $columns[] = $columnName;
                $value = $this->{$property->getName()};
                if ($value instanceof \Stringable) {
                    $value = (string)$value;
                } elseif ($value instanceof \DateTime) {
                    $value = $value->format('c');
                } elseif (is_array($value)) {
                    $value = json_encode($value);
                }
                $values[] = $value;
            }
        }
        $allColumns = '"' . join('","', $columns) . '"';
        $placeholders = join(',', array_pad([], count($values), "?"));
        return [
            "INSERT INTO \"{$schemaName}\" ({$allColumns}) VALUES ({$placeholders})",
            $values,
        ];
    }

    /**
     * This is very blunt. The next level of sophistication would be doing
     * dirty-property tracking so we only update what has changed.
     *
     * @return array{string, array{mixed}}
     */
    public function getUpdateStatementAndValues(): array
    {
        /* Note: this using ANSI SQL quoting style. */
        $schemaName = static::getSchemaName();

        $properties =
            (new \ReflectionClass($this))
                ->getProperties(\ReflectionProperty::IS_PUBLIC);

        $primaryKeyColumn = $primaryKeyValue = null;
        $columns = [];
        $values = [];
        foreach ($properties as $property) {
            $columnName = self::camelCaseToSnakeCase($property->getName());
            $value = $this->{$property->getName()};
            $onUpdate = $property->getAttributes(SqlOnUpdate::class);

            if ($property->getAttributes(PrimaryKey::class) !== []) {
                $primaryKeyColumn = $columnName;
                $primaryKeyValue = $value;
            } elseif ($onUpdate !== []) {
                $updateValue = $onUpdate[0]->getArguments()[0];
                if ($updateValue instanceof DefaultConstant) {
                    $columns[] = [ $columnName, $updateValue->value ];
                } else {
                    $columns[] = $columnName;
                    $values[] = $updateValue;
                }
            } else {
                if ($value instanceof \Stringable) {
                    $value = (string)$value;
                } elseif ($value instanceof \DateTime) {
                    $value = $value->format('c');
                } elseif (is_array($value)) {
                    $value = json_encode($value);
                }
                $columns[] = $columnName;
                $values[] = $value;
            }
        }
        $allColumns = array_map(
            function ($column) {
                if (is_array($column)) {
                    return "\"{$column[0]}\" = {$column[1]}";
                } else {
                    return "\"{$column}\" = ?";
                }
            },
            $columns
        );
        $allColumns = join(',', $allColumns);
        $values[] = $primaryKeyValue;
        return [
            "UPDATE \"{$schemaName}\" SET {$allColumns} WHERE \"{$primaryKeyColumn}\" = ?",
            $values,
        ];
    }
}
