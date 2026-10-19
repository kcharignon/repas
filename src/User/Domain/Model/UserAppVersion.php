<?php

namespace Repas\User\Domain\Model;


use DateTimeImmutable;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * La dernière version de l'application vue pour un compte, sur une plateforme (DRV-637) : une seule ligne par compte et par
 * plateforme, `(user_id, platform)`, remplacée à chaque envoi. Pas d'historique.
 *
 * Vie privée : seule la version est retenue, avec l'heure où elle a été vue ; aucun identifiant d'appareil, aucun modèle de
 * téléphone. La ligne disparaît avec le compte (`DeleteUserHandler`, ADR 0021).
 */
final class UserAppVersion implements ModelInterface
{
    use ModelTrait;

    private function __construct(
        private string $userId,
        private AppPlatform $platform,
        private AppVersion $version,
        private DateTimeImmutable $seenAt,
    ) {
    }

    public static function seen(string $userId, AppPlatform $platform, AppVersion $version, DateTimeImmutable $seenAt): self
    {
        return new self(
            userId: $userId,
            platform: $platform,
            version: $version,
            seenAt: $seenAt,
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            userId: $datas['user_id'],
            platform: $datas['platform'],
            version: $datas['version'],
            seenAt: $datas['seen_at'],
        );
    }

    /**
     * La clé est le couple (compte, plateforme).
     */
    public function getId(): string
    {
        return $this->userId . '|' . $this->platform->value;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getPlatform(): AppPlatform
    {
        return $this->platform;
    }

    public function getVersion(): AppVersion
    {
        return $this->version;
    }

    public function getSeenAt(): DateTimeImmutable
    {
        return $this->seenAt;
    }
}
