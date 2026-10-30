<?php

namespace Repas\Drive\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `drive_retailer_preference` (DRV-703) : le drive habituel et la dernière enseigne utilisée du compte `userId`, une ligne par
 * compte. Sert à créer le schéma et à la suppression du compte ; les lectures et les écritures passent par
 * `RetailerPreferencePostgreSQLRepository` (SQL direct, une requête chacune). Jamais hydratée ni écrite par le code. Aucune clé étrangère :
 * `userId` est effacé avec le compte (ADR 0021), l'enseigne n'est qu'un identifiant (ADR 0013).
 */
#[ORM\Entity]
#[ORM\Table(name: 'drive_retailer_preference')]
class RetailerPreference
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    public string $userId;

    #[ORM\Column(name: 'default_retailer', type: Types::STRING, length: 20, nullable: true)]
    public ?string $defaultRetailer = null;

    #[ORM\Column(name: 'last_used_retailer', type: Types::STRING, length: 20, nullable: true)]
    public ?string $lastUsedRetailer = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $updatedAt;
}
