<?php

namespace Repas\Voice\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `voice_access_grant` (ADR 0029 décision 6) : la paire de jetons (accès et rafraîchissement) émise à une liaison.
 * Seuls les HACHÉS (SHA-256, hexadécimal) sont stockés, jamais les jetons : le haché de l'accès est la clé primaire, celui du
 * rafraîchissement a un index unique. Un jeton est de portée unique (le webhook) et dépend de sa liaison (`link_id`).
 *
 * `user_id` (VOX-202) est le compte de la liaison, recopié pour que résoudre un jeton ne lise qu'une ligne et que la purge d'un
 * compte l'efface (`PurgeUserVoiceData`, ADR 0021). `used_at` (VOX-202) marque le jeton de rafraîchissement déjà échangé contre son
 * successeur (rotation) : la ligne reste jusqu'à l'expiration de ce jeton, pour reconnaître un rejeu. Aucune clé étrangère.
 */
#[ORM\Entity]
#[ORM\Table(name: 'voice_access_grant')]
#[ORM\UniqueConstraint(name: 'uniq_voice_access_grant_refresh', columns: ['refresh_hash'])]
#[ORM\Index(name: 'idx_voice_access_grant_link', columns: ['link_id'])]
#[ORM\Index(name: 'idx_voice_access_grant_user', columns: ['user_id'])]
class AccessGrant
{
    #[ORM\Id]
    #[ORM\Column(name: 'access_hash', type: Types::STRING, length: 64)]
    public string $accessHash;

    #[ORM\Column(name: 'refresh_hash', type: Types::STRING, length: 64)]
    public string $refreshHash;

    #[ORM\Column(name: 'link_id', type: Types::STRING, length: 36)]
    public string $linkId;

    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    public string $userId;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'refresh_expires_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $refreshExpiresAt;

    #[ORM\Column(name: 'used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $usedAt = null;
}
