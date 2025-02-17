# Uakari: a lightweight database entity mapper in PHP

[Uakari][uakari] is a lightweight database entity mapper.

## Features

To be seen, really.

## Installation

This is installable and autoloadable via Composer as
[jimwins/uakari](https://packagist.org/packages/jimwins/uakari).
If you aren't familiar with the Composer dependency manager for PHP,
[you should read this first](https://getcomposer.org/doc/00-intro.md).

```bash
$ composer require jimwins/uakari --prefer-dist
```

## Example

```php
<?php

use DateTime;
use Uakari\Attributes\AutoIncrement;
use Uakari\Attributes\Indexed;
use Uakari\Attributes\PrimaryKey;
use Uakari\Attributes\SqlDefault;
use Uakari\Attributes\SqlOnUpdate;
use Uakari\Attributes\SqlType;
use Uakari\Attributes\Unique;
use Uakari\Entity;
use Uakari\Enums\DefaultConstant;

class MyEntity {
    #[PrimaryKey, AutoIncrement]
    public int $id;

    #[Indexed]
    public string $name;

    #[SqlDefault('whatever')]
    public string $defaulted;

    /** @var array<mixed> */
    public ?array $arrayValue;

    #[SqlDefault(DefaultConstant::CurrentTimestamp)]
    public DateTime $createdAt;

    #[SqlOnUpdate(DefaultConstant::CurrentTimestamp)]
    public ?DateTime $updatedAt;
}

$pdo = new PDO('sqlite::memory:');

$repository = new Repository($pdo, MyEntity::class);

$repository->createSchema();

$entities = [
    MyEntity::create(
        name: 'Bob',
        createdAt: new DateTime('2025-02-01 10:00:00'),
    ),
    MyEntity::create(
        name: 'Sally',
        arrayValue: [1, 2, 3],
    ),
    MyEntity::create(
        name: 'Fred',
        arrayValue: ['a' => 'b'],
    ),
];

foreach ($entities as $entity)
{
    $repository->add($entity);
}

$entities = $repository->getAll();

var_dump($entities);

$entity = $repository->get(1);
$entity->name = 'Howard';

$updated = $repository->update($entity);

var_dump($updated);

$repository->delete($entity);
```

## Running tests

``` bash
$ composer test
```

## About the uakari

[Uakari](https://en.wikipedia.org/wiki/Uakari) are New World monkeys of the
genus *Cacaja*, found in the north-western Amazon basin.

[Jim Winstead](mailto:jimw@trainedmonkey.com), February 2025

[uakari]: https://github.com/jimwins/uakari
