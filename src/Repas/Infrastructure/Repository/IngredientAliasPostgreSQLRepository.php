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
    /** Valeur de `ingredient_alias.source` d'un alias écrit ou déplacé par une fusion (ING-005). */
    public const string SOURCE_MERGE = 'merge';

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

    /**
     * Les alias VALIDES d'un utilisateur pour des slugs : l'ingrédient désigné existe et lui appartient (jointure sur `ingredient`).
     */
    private const string VALID_ALIASES = 'SELECT a.alias_slug, a.ingredient_slug FROM ingredient_alias a'
        . ' INNER JOIN ingredient own ON own.slug = a.ingredient_slug AND own.creator = a.user_id'
        . ' WHERE a.user_id = ? AND a.alias_slug IN (?)';

    public function resolve(string $userId, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        /** @var array<string, string> $resolved */
        $resolved = $this->connection()->fetchAllKeyValue(
            self::VALID_ALIASES,
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

    public function mergeInto(string $userId, string $absorbedSlug, string $keptSlug, DateTimeImmutable $now): void
    {
        $connection = $this->connection();

        // Les alias qui désignaient l'absorbé désignent le gardé ; celui dont la clé est le slug gardé disparaît (une seule requête : les
        // deux lignes touchées sont distinctes, la clé gardée n'est jamais une valeur à déplacer)
        $connection->executeStatement(
            'WITH dropped AS (DELETE FROM ingredient_alias WHERE user_id = :u AND alias_slug = :kept RETURNING 1)'
            . ' UPDATE ingredient_alias SET ingredient_slug = :kept, source = :source WHERE user_id = :u AND ingredient_slug = :absorbed AND alias_slug <> :kept',
            ['u' => $userId, 'kept' => $keptSlug, 'absorbed' => $absorbedSlug, 'source' => self::SOURCE_MERGE],
        );

        // L'alias de la fusion lui-même : « absorbé -> gardé ». Un alias qui avait déjà cette clé (le slug d'un ingrédient disparu qui
        // revient) est remplacé : l'ingrédient absorbé n'existe plus, rien d'autre ne peut y répondre.
        $connection->executeStatement(
            'INSERT INTO ingredient_alias (user_id, alias_slug, ingredient_slug, kind, source, created_at) VALUES (:u, :absorbed, :kept, :kind, :source, :now)'
            . ' ON CONFLICT (user_id, alias_slug) DO UPDATE SET ingredient_slug = EXCLUDED.ingredient_slug, kind = EXCLUDED.kind, source = EXCLUDED.source, created_at = EXCLUDED.created_at',
            ['u' => $userId, 'absorbed' => $absorbedSlug, 'kept' => $keptSlug, 'kind' => AliasKind::MERGE->value, 'source' => self::SOURCE_MERGE, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
