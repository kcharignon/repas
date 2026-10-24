<?php

namespace Repas\Repas\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `ingredient_distinct` (ADR 0028, ING-006) : « pour cet utilisateur, ces deux ingrédients ne sont PAS le même ». Sert à créer
 * le schéma et à décrire les colonnes ; les lectures et les écritures passent par `IngredientDistinctPostgreSQLRepository` et
 * `IngredientDuplicatePostgreSQLLoader` (SQL direct), jamais par l'ORM : la ligne n'est jamais hydratée.
 *
 * Les deux slugs sont rangés dans l'ordre des octets (`first_slug < second_slug`, contrainte `CHECK` posée par la migration avec
 * `COLLATE "C"`, que Doctrine ne décrit pas). Aucune clé étrangère (ADR 0013, 0021) : `userId` est effacé avec le compte
 * (`PurgeUserDataHandler`), les lignes qui nomment un ingrédient le sont avec lui (`RemoveIngredientHandler`, `MergeIngredientsHandler`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ingredient_distinct')]
#[ORM\Index(name: 'idx_ingredient_distinct_second', columns: ['user_id', 'second_slug'])]
class IngredientDistinct
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    public string $userId;

    #[ORM\Id]
    #[ORM\Column(name: 'first_slug', type: Types::STRING, length: 255)]
    public string $firstSlug;

    #[ORM\Id]
    #[ORM\Column(name: 'second_slug', type: Types::STRING, length: 255)]
    public string $secondSlug;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $createdAt;
}
