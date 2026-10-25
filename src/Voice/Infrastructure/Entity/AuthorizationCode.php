<?php

namespace Repas\Voice\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `voice_authorization_code` (ADR 0029 décision 6) : le code à usage unique que l'utilisateur donne à Alexa en
 * autorisant la liaison, échangé ensuite contre des jetons. Seul le HACHÉ du code (SHA-256, hexadécimal) est stocké, jamais le code :
 * c'est la clé primaire. `code_challenge` est celui de PKCE (S256), `used_at` marque l'usage unique et `link_id` (VOX-202) la liaison
 * créée en l'échangeant : rejouer le code la révoque. Aucune clé étrangère : `userId` est effacé avec le compte
 * (`PurgeUserVoiceData`, ADR 0021).
 */
#[ORM\Entity]
#[ORM\Table(name: 'voice_authorization_code')]
#[ORM\Index(name: 'idx_voice_authorization_code_user', columns: ['user_id'])]
class AuthorizationCode
{
    #[ORM\Id]
    #[ORM\Column(name: 'code_hash', type: Types::STRING, length: 64)]
    public string $codeHash;

    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    public string $userId;

    #[ORM\Column(name: 'client_id', type: Types::STRING, length: 100)]
    public string $clientId;

    #[ORM\Column(name: 'redirect_uri', type: Types::STRING, length: 2048)]
    public string $redirectUri;

    #[ORM\Column(name: 'code_challenge', type: Types::STRING, length: 128)]
    public string $codeChallenge;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $usedAt = null;

    #[ORM\Column(name: 'link_id', type: Types::STRING, length: 36, nullable: true)]
    public ?string $linkId = null;
}
