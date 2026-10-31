<?php

namespace Repas\Repas\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `shopping_list_type` (ADR 0036, LST-002) : un type de liste de courses que l'utilisateur crée (« Boucherie »), propre à son
 * compte. Sert à créer le schéma et à décrire les colonnes ; les lectures et les écritures passent par
 * `ShoppingListTypePostgreSQLRepository` et `ShoppingListTypePostgreSQLLoader` (SQL direct), jamais par l'ORM : la ligne n'est jamais hydratée.
 *
 * Aucune clé étrangère (ADR 0013, 0021) et aucune contrainte unique (le nom normalisé est unique par compte par règle de l'application) :
 * `ownerId` est effacé avec le compte (`PurgeUserDataHandler`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'shopping_list_type')]
#[ORM\Index(name: 'idx_shopping_list_type_owner', columns: ['owner_id', 'created_at'])]
class ShoppingListType
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\Column(name: 'owner_id', type: Types::STRING, length: 36)]
    public string $ownerId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $name;

    #[ORM\Column(name: 'slugged_name', type: Types::STRING, length: 255)]
    public string $sluggedName;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $createdAt;
}
