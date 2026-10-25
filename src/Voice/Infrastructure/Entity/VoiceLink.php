<?php

namespace Repas\Voice\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `voice_link` (ADR 0029 décision 6, VOX-201) : la liaison d'un compte Repas avec un compte Alexa. Une ligne par liaison ;
 * `alexa_user_id` n'est connu qu'à la première requête d'Alexa (donc vide à la création) et ne sert qu'à reconnaître une liaison
 * existante (décision 10). `revoked_at` : liaison retirée par l'utilisateur, ses jetons ne servent plus. Aucune clé étrangère :
 * `userId` est effacé avec le compte (`PurgeUserVoiceData`, ADR 0021).
 */
#[ORM\Entity]
#[ORM\Table(name: 'voice_link')]
#[ORM\Index(name: 'idx_voice_link_user', columns: ['user_id'])]
class VoiceLink
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    public string $userId;

    #[ORM\Column(name: 'alexa_user_id', type: Types::STRING, length: 255, nullable: true)]
    public ?string $alexaUserId = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $revokedAt = null;
}
