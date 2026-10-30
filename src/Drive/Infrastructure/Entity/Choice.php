<?php

namespace Repas\Drive\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `drive_choice`, lu par les loaders (DQL) et utilisé pour créer le schéma ; les écritures passent par
 * `ChoicePostgreSQLRepository` (une requête par table pour tout un lot). Jamais hydratée ni écrite par le code : les
 * propriétés sont publiques et sans accesseur pour cette raison. Aucune clé étrangère : `userId` est effacé avec le
 * compte (ADR 0021).
 */
#[ORM\Entity]
#[ORM\Table(name: 'drive_choice')]
#[ORM\UniqueConstraint(name: 'uniq_drive_choice_user_store_ingredient', columns: ['user_id', 'retailer', 'store_id', 'ingredient_slug'])]
class Choice
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    public string $userId;

    #[ORM\Column(type: Types::STRING, length: 20)]
    public string $retailer;

    #[ORM\Column(name: 'store_id', type: Types::STRING, length: 40)]
    public string $storeId;

    #[ORM\Column(name: 'ingredient_slug', type: Types::STRING, length: 255)]
    public string $ingredientSlug;

    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $kind;

    #[ORM\Column(name: 'search_query', type: Types::STRING, length: 80, nullable: true)]
    public ?string $searchQuery = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $updatedAt;
}
