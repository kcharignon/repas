<?php

namespace Repas\Repas\Infrastructure\Repository;


use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Interface\ShoppingListTypeRepository;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Repas\Infrastructure\Entity\ShoppingListType as ShoppingListTypeEntity;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

/**
 * Écritures en SQL direct (ADR 0036) : le type et ses rattachements de rayons ne sont jamais hydratés par l'ORM. La clé primaire
 * `(owner_id, department_slug)` de `department_list_type` fait qu'un rayon est dans un seul type à la fois : le rattacher à un autre type
 * de l'utilisateur le déplace (`INSERT … ON CONFLICT DO UPDATE`).
 */
readonly class ShoppingListTypePostgreSQLRepository extends PostgreSQLRepository implements ShoppingListTypeRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, ShoppingListTypeEntity::class);
    }

    public function findOneById(string $id): ShoppingListType
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT id, owner_id, name, slugged_name, created_at FROM shopping_list_type WHERE id = ?',
            [$id],
        );
        if ($row === false) {
            throw ShoppingListTypeException::notFound();
        }

        return ShoppingListType::load([
            'id' => $row['id'],
            'owner_id' => $row['owner_id'],
            'name' => $row['name'],
            'slugged_name' => $row['slugged_name'],
            'created_at' => new DateTimeImmutable($row['created_at']),
            'department_slugs' => $this->connection()->fetchFirstColumn(
                'SELECT department_slug FROM department_list_type WHERE owner_id = ? AND list_type_id = ? ORDER BY department_slug',
                [$row['owner_id'], $id],
            ),
        ]);
    }

    public function ownerHasAtLeast(string $ownerId, int $count): bool
    {
        // Le `$count`-ième type existe-t-il ? Une ligne lue après avoir sauté les `$count - 1` premières
        return $this->connection()->fetchOne(
            'SELECT 1 FROM shopping_list_type WHERE owner_id = ? ORDER BY created_at, id LIMIT 1 OFFSET ' . max(0, $count - 1),
            [$ownerId],
        ) !== false;
    }

    public function ownerHasTypeNamed(string $ownerId, string $sluggedName, ?string $exceptId = null): bool
    {
        return $this->connection()->fetchOne(
            'SELECT 1 FROM shopping_list_type WHERE owner_id = ? AND slugged_name = ? AND id <> ? LIMIT 1',
            [$ownerId, $sluggedName, $exceptId ?? ''],
        ) !== false;
    }

    public function save(ShoppingListType $listType): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO shopping_list_type (id, owner_id, name, slugged_name, created_at) VALUES (?, ?, ?, ?, ?) '
            . 'ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, slugged_name = EXCLUDED.slugged_name',
            [$listType->getId(), $listType->getOwnerId(), $listType->getName(), $listType->getSluggedName(), $listType->getCreatedAt()],
            [4 => Types::DATETIME_IMMUTABLE],
        );

        $slugs = $listType->getDepartmentSlugs();
        // Les rayons que le type n'a plus (tous, s'il n'en a plus aucun)
        if ($slugs === []) {
            $this->connection()->executeStatement(
                'DELETE FROM department_list_type WHERE owner_id = ? AND list_type_id = ?',
                [$listType->getOwnerId(), $listType->getId()],
            );

            return;
        }
        $this->connection()->executeStatement(
            'DELETE FROM department_list_type WHERE owner_id = ? AND list_type_id = ? AND department_slug NOT IN (?)',
            [$listType->getOwnerId(), $listType->getId(), $slugs],
            [ParameterType::STRING, ParameterType::STRING, ArrayParameterType::STRING],
        );

        // Ceux qu'il a : rattachés, ou déplacés depuis le type de l'utilisateur qui les avait (clé = utilisateur + rayon)
        $values = implode(', ', array_fill(0, count($slugs), '(?, ?, ?)'));
        $parameters = [];
        foreach ($slugs as $slug) {
            array_push($parameters, $listType->getOwnerId(), $slug, $listType->getId());
        }
        $this->connection()->executeStatement(
            'INSERT INTO department_list_type (owner_id, department_slug, list_type_id) VALUES ' . $values
            . ' ON CONFLICT (owner_id, department_slug) DO UPDATE SET list_type_id = EXCLUDED.list_type_id',
            $parameters,
        );
    }

    public function delete(ShoppingListType $listType): void
    {
        // Une seule instruction pour les deux tables (la suppression d'un type est comptée au budget SQL)
        $this->connection()->executeStatement(
            'WITH removed_type AS (DELETE FROM shopping_list_type WHERE id = ? AND owner_id = ?) DELETE FROM department_list_type WHERE owner_id = ? AND list_type_id = ?',
            [$listType->getId(), $listType->getOwnerId(), $listType->getOwnerId(), $listType->getId()],
        );
    }

    public function deleteByOwner(string $ownerId): void
    {
        // Une seule instruction pour les deux tables (la suppression d'un compte est comptée au budget SQL)
        $this->connection()->executeStatement(
            'WITH removed_types AS (DELETE FROM shopping_list_type WHERE owner_id = ?) DELETE FROM department_list_type WHERE owner_id = ?',
            [$ownerId, $ownerId],
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
