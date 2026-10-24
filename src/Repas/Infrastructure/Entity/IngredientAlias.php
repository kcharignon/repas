<?php

namespace Repas\Repas\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\AliasKind;

/**
 * Mapping de `ingredient_alias` (ADR 0028, ING-001) : « pour cet utilisateur, `aliasSlug` est son ingrédient `ingredientSlug` ».
 * Sert à créer le schéma et à décrire les colonnes ; les lectures et les écritures passent par
 * `IngredientAliasPostgreSQLRepository` (SQL direct, `INSERT … ON CONFLICT`), jamais par l'ORM : la ligne n'est jamais hydratée.
 * Aucune clé étrangère (ADR 0013, 0021) : `userId` est effacé avec le compte (`PurgeUserDataHandler`), l'ingrédient désigné par
 * `ingredientSlug` l'est avec l'alias (`RemoveIngredientHandler`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ingredient_alias')]
#[ORM\Index(name: 'idx_ingredient_alias_ingredient', columns: ['user_id', 'ingredient_slug'])]
class IngredientAlias
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    public string $userId;

    #[ORM\Id]
    #[ORM\Column(name: 'alias_slug', type: Types::STRING, length: 255)]
    public string $aliasSlug;

    #[ORM\Column(name: 'ingredient_slug', type: Types::STRING, length: 255)]
    public string $ingredientSlug;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AliasKind::class)]
    public AliasKind $kind;

    #[ORM\Column(type: Types::STRING, length: 64)]
    public string $source;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $createdAt;
}
