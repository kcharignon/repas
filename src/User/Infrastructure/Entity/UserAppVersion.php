<?php

namespace Repas\User\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Repas\User\Domain\Model\AppPlatform;
use Repas\User\Domain\Model\AppVersion;

/**
 * Mapping de `user_app_version` (DRV-637) : la dernière version de l'application vue pour un compte, une ligne par plateforme.
 * La clé `(user_id, platform)` porte l'écriture (`ON CONFLICT`), la lecture du compte et la suppression des lignes d'un compte.
 * Lue par `UserPostgreSQLLoader` (jointure), écrite et supprimée par `UserAppVersionPostgreSQLRepository` (SQL direct) ; jamais
 * hydratée ni écrite par l'ORM. Aucune clé étrangère : `userId` est effacé avec le compte (`DeleteUserHandler`, ADR 0021).
 *
 * Vie privée : la version et l'heure, rien d'autre (aucun identifiant d'appareil, aucun modèle de téléphone).
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_app_version')]
class UserAppVersion
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    public string $userId;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 16, enumType: AppPlatform::class)]
    public AppPlatform $platform;

    #[ORM\Column(type: Types::STRING, length: AppVersion::MAX_LENGTH)]
    public string $version;

    #[ORM\Column(name: 'seen_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $seenAt;
}
