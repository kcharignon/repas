<?php

namespace Repas\Repas\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `shopping_list_archive` (ADR 0041) : la copie à plat d'une liste de courses supprimée. Écrite par une seule instruction SQL
 * (`ShoppingListArchivePostgreSQLRepository::archive`) et lue en SQL par le propriétaire du produit : cette entité ne décrit que le schéma. Aucune
 * clé étrangère, aucune jointure à faire : le nom de la liste, du type, des ingrédients, des unités et des recettes est copié avec elle, et les
 * lignes et les repas sont des tableaux JSONB (une clé par colonne, en `snake_case`). `owner_id` est effacé avec le compte (`PurgeUserData`, ADR 0021).
 */
#[ORM\Entity]
#[ORM\Table(name: 'shopping_list_archive')]
#[ORM\Index(name: 'idx_shopping_list_archive_owner', columns: ['owner_id'])]
#[ORM\Index(name: 'idx_shopping_list_archive_list', columns: ['shopping_list_id'])]
class ShoppingListArchive
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\Column(name: 'shopping_list_id', type: Types::STRING, length: 255)]
    public string $shoppingListId;

    #[ORM\Column(name: 'owner_id', type: Types::STRING, length: 255)]
    public string $ownerId;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $status;

    #[ORM\Column(name: 'list_type_id', type: Types::STRING, length: 36, nullable: true)]
    public ?string $listTypeId = null;

    #[ORM\Column(name: 'list_type_name', type: Types::STRING, length: 255, nullable: true)]
    public ?string $listTypeName = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: Types::INTEGER)]
    public int $version = 1;

    #[ORM\Column(name: 'archived_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $archivedAt;

    #[ORM\Column(type: Types::STRING, length: 32)]
    public string $cause;

    #[ORM\Column(name: 'line_count', type: Types::INTEGER)]
    public int $lineCount = 0;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $lines = [];

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $meals = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'part_shares', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $partShares = null;
}
