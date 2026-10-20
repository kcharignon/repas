<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Interface\IngredientDistinctRepository;
use Repas\Repas\Domain\Model\IngredientDistinctPair;
use Repas\Repas\Infrastructure\Entity\IngredientDistinct as IngredientDistinctEntity;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

/**
 * Écritures en SQL direct (ADR 0028) : une requête par appel, la ligne n'est jamais hydratée par l'ORM. La clé primaire
 * `(user_id, first_slug, second_slug)` arbitre la repose et deux « Pas le même » simultanés de la même paire.
 */
readonly class IngredientDistinctPostgreSQLRepository extends PostgreSQLRepository implements IngredientDistinctRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, IngredientDistinctEntity::class);
    }

    public function save(IngredientDistinctPair $pair): bool
    {
        return $this->connection()->executeStatement(
            'INSERT INTO ingredient_distinct (user_id, first_slug, second_slug, created_at) VALUES (?, ?, ?, ?) '
            . 'ON CONFLICT (user_id, first_slug, second_slug) DO NOTHING',
            [$pair->getUserId(), $pair->getFirstSlug(), $pair->getSecondSlug(), $pair->getCreatedAt()],
            [3 => Types::DATETIME_IMMUTABLE],
        ) === 1;
    }

    public function deleteByUser(string $userId): void
    {
        $this->connection()->executeStatement('DELETE FROM ingredient_distinct WHERE user_id = ?', [$userId]);
    }

    public function deleteByIngredientSlug(string $userId, string $ingredientSlug): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM ingredient_distinct WHERE user_id = ? AND (first_slug = ? OR second_slug = ?)',
            [$userId, $ingredientSlug, $ingredientSlug],
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
