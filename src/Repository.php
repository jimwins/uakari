<?php

declare(strict_types=1);

namespace Uakari;

use PDO;
use Uakari\Entity;
use Uakari\RepositoryInterface;

/**
 * @template E of Entity
 * @implements RepositoryInterface<E>
 * @property class-string<E> $className
 */
class Repository implements RepositoryInterface
{
    /**
     * @param class-string<E> $className
     * @return RepositoryInterface<E>
     */
    public function __construct(
        private PDO $pdo,
        private string $className,
    ) {
    }

    public function createSchema(): void
    {
        $createStatements = ($this->className)::getCreateTableStatement($this->pdo);
        foreach ($createStatements as $statement) {
            $this->pdo->query($statement);
        }
    }

    /**
     * @param E $entity
     */
    public function add($entity): object
    {
        [$statement, $values] = $entity->getInsertStatementAndValues();
        $stmt = $this->pdo->prepare($statement);
        $res = $stmt->execute($values);
        $id = (int)$this->pdo->lastInsertId();

        return $this->get($id);
    }

    /**
     * @return E
     */
    public function get(int $id): object
    {
        $schemaName = ($this->className)::getSchemaName();
        $primaryKeyName = ($this->className)::getPrimaryKeyName();

        $stmt = $this->pdo->prepare(
            "SELECT * FROM \"{$schemaName}\" WHERE \"{$primaryKeyName}\" = ?"
        );
        $stmt->execute([ $id ]);

        if (!$stmt) {
            throw new \Exception("Failed to prepare and execute SQL statement");
        }

        /** @var list<E> */
        $entities = $stmt->fetchAll(
            PDO::FETCH_FUNC,
            fn (...$cols) => ($this->className)::fromPdoRow($stmt, $cols)
        );
        return $entities[0];
    }

    /**
     * @return list<E>
     */
    public function getAll(): array
    {
        $schemaName = ($this->className)::getSchemaName();
        $res = $this->pdo->query("SELECT * FROM \"{$schemaName}\"");
        if (!$res) {
            throw new \Exception("Failed to prepare and execute SQL statement");
        }

        /** @var list<E> */
        $entities = $res->fetchAll(
            PDO::FETCH_FUNC,
            fn (...$cols) => ($this->className)::fromPdoRow($res, $cols)
        );
        return $entities;
    }

    /**
     * @param E $entity
     */
    public function update(object $entity): object
    {
        [$statement, $values] = $entity->getUpdateStatementAndValues();
        $stmt = $this->pdo->prepare($statement);
        $stmt->execute($values);

        $primaryKeyName = ($this->className)::getPrimaryKeyName();

        /** @phpstan-ignore argument.type */
        return $this->get($entity->$primaryKeyName);
    }

    /**
     * @param E $entity
     */
    public function delete(object $entity): void
    {
        $schemaName = ($this->className)::getSchemaName();
        $primaryKeyName = ($this->className)::getPrimaryKeyName();

        $stmt = $this->pdo->prepare(
            "DELETE FROM \"{$schemaName}\" WHERE \"{$primaryKeyName}\" = ?"
        );
        $stmt->execute([ $entity->$primaryKeyName ]);

        if (!$stmt) {
            throw new \Exception("Failed to prepare and execute SQL statement");
        }
    }
}
