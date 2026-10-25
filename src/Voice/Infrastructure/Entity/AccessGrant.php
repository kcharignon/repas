<?php

namespace Repas\Voice\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `voice_access_grant` (ADR 0029 décision 6, VOX-201) : la paire de jetons (accès et rafraîchissement) émise à une liaison.
 * Seuls les HACHÉS (SHA-256, hexadécimal) sont stockés, jamais les jetons : le haché de l'accès est la clé primaire, celui du
 * rafraîchissement a un index unique. Un jeton est de portée unique (le webhook) et dépend de sa liaison (`link_id`). Aucune clé
 * étrangère, et pas de colonne `user_id` : le compte se retrouve par la liaison, et la purge d'un compte efface ses jetons par elle
 * (`PurgeUserVoiceData`, ADR 0021).
 *
 * Mapping seul pour l'instant : VOX-202 ajoute le modèle de domaine, le repository et les conversions.
 */
#[ORM\Entity]
#[ORM\Table(name: 'voice_access_grant')]
#[ORM\UniqueConstraint(name: 'uniq_voice_access_grant_refresh', columns: ['refresh_hash'])]
#[ORM\Index(name: 'idx_voice_access_grant_link', columns: ['link_id'])]
class AccessGrant
{
    #[ORM\Id]
    #[ORM\Column(name: 'access_hash', type: Types::STRING, length: 64)]
    public string $accessHash;

    #[ORM\Column(name: 'refresh_hash', type: Types::STRING, length: 64)]
    public string $refreshHash;

    #[ORM\Column(name: 'link_id', type: Types::STRING, length: 36)]
    public string $linkId;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'refresh_expires_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $refreshExpiresAt;
}
