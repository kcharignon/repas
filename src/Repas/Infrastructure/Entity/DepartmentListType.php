<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `department_list_type` (ADR 0036, LST-002) : « pour cet utilisateur, les produits de ce rayon vont dans ce type de liste ».
 * La clé est (utilisateur, rayon) : un rayon est dans un seul type à la fois, rattacher un rayon déjà pris le déplace. Sert à créer le
 * schéma ; les lectures et les écritures passent par `ShoppingListTypePostgreSQLRepository` et `ShoppingListTypePostgreSQLLoader` (SQL
 * direct, `INSERT … ON CONFLICT`), jamais par l'ORM.
 *
 * Aucune clé étrangère (ADR 0013, 0021) : `ownerId` est effacé avec le compte (`PurgeUserDataHandler`), la ligne l'est avec son type
 * (`RemoveShoppingListTypeHandler`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'department_list_type')]
#[ORM\Index(name: 'idx_department_list_type_type', columns: ['list_type_id'])]
class DepartmentListType
{
    #[ORM\Id]
    #[ORM\Column(name: 'owner_id', type: Types::STRING, length: 36)]
    public string $ownerId;

    #[ORM\Id]
    #[ORM\Column(name: 'department_slug', type: Types::STRING, length: 255)]
    public string $departmentSlug;

    #[ORM\Column(name: 'list_type_id', type: Types::STRING, length: 36)]
    public string $listTypeId;
}
