<?php

namespace Repas\Repas\Infrastructure\Repository;


use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Infrastructure\Entity\IngredientAlias as IngredientAliasEntity;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

/**
 * Lectures et écritures en SQL direct (ADR 0028) : une requête par appel, sans passer par l'identity map (la ligne n'est jamais
 * hydratée par l'ORM). La clé primaire `(user_id, alias_slug)` arbitre l'adoption simultanée du même commun.
 */
readonly class IngredientAliasPostgreSQLRepository extends PostgreSQLRepository implements IngredientAliasRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, IngredientAliasEntity::class);
    }

    public function find(string $userId, string $aliasSlug): ?IngredientAlias
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT user_id, alias_slug, ingredient_slug, kind, source, created_at FROM ingredient_alias WHERE user_id = ? AND alias_slug = ?',
            [$userId, $aliasSlug],
        );

        if ($row === false) {
            return null;
        }

        return IngredientAlias::load([
            'user_id' => $row['user_id'],
            'alias_slug' => $row['alias_slug'],
            'ingredient_slug' => $row['ingredient_slug'],
            'kind' => AliasKind::from($row['kind']),
            'source' => $row['source'],
            'created_at' => new DateTimeImmutable($row['created_at']),
        ]);
    }

    public function resolve(string $userId, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        /** @var array<string, string> $resolved */
        $resolved = $this->connection()->fetchAllKeyValue(
            'SELECT alias_slug, ingredient_slug FROM ingredient_alias WHERE user_id = ? AND alias_slug IN (?)',
            [$userId, array_values(array_unique($slugs))],
            [1 => ArrayParameterType::STRING],
        );

        return $resolved;
    }

    public function isAdopter(string $userId): bool
    {
        return $this->connection()->fetchOne(
            'SELECT 1 FROM ingredient_alias WHERE user_id = ? AND kind = ? LIMIT 1',
            [$userId, AliasKind::ADOPTION->value],
        ) !== false;
    }

    public function save(IngredientAlias $alias): bool
    {
        return $this->connection()->executeStatement(
            'INSERT INTO ingredient_alias (user_id, alias_slug, ingredient_slug, kind, source, created_at) VALUES (?, ?, ?, ?, ?, ?) '
            . 'ON CONFLICT (user_id, alias_slug) DO NOTHING',
            [
                $alias->getUserId(),
                $alias->getAliasSlug(),
                $alias->getIngredientSlug(),
                $alias->getKind()->value,
                $alias->getSource(),
                $alias->getCreatedAt(),
            ],
            [5 => Types::DATETIME_IMMUTABLE],
        ) === 1;
    }

    public function deleteByUser(string $userId): void
    {
        $this->connection()->executeStatement('DELETE FROM ingredient_alias WHERE user_id = ?', [$userId]);
    }

    public function deleteByAliasSlug(string $userId, string $aliasSlug): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM ingredient_alias WHERE user_id = ? AND alias_slug = ?',
            [$userId, $aliasSlug],
        );
    }

    public function deleteByIngredientSlug(string $userId, string $ingredientSlug): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM ingredient_alias WHERE user_id = ? AND ingredient_slug = ?',
            [$userId, $ingredientSlug],
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
