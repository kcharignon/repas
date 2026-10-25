<?php

namespace Repas\Voice\Domain\Model;


use DateInterval;
use DateTimeImmutable;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * Une paire de jetons d'une liaison (ADR 0029 décision 6) : un jeton d'accès (une heure) et un jeton de rafraîchissement (90 jours)
 * qui ne sert qu'une fois. Les jetons n'existent jamais ici : seuls leurs hachés (`OpaqueToken::hash`) sont conservés, celui de
 * l'accès sert d'identifiant.
 *
 * Rafraîchir consomme la paire et en émet une autre (`rotate()`, rotation comme le refresh token du SPA, ADR 0015) ; la paire
 * consommée GARDE sa ligne jusqu'à l'expiration de son jeton de rafraîchissement, pour qu'on reconnaisse un rejeu, qui révoque la
 * liaison. Son jeton d'accès reste valable jusqu'à sa propre expiration (Amazon : ne pas invalider un jeton d'accès avant `expires_in`).
 */
final class AccessGrant implements ModelInterface
{
    use ModelTrait;

    /** Durée de vie du jeton d'accès, en secondes : `expires_in` de la réponse (Amazon : au moins 3600). */
    public const int ACCESS_TTL_SECONDS = 3600;
    /** Durée de vie du jeton de rafraîchissement, renouvelée à chaque rotation : une liaison utilisée au moins tous les 90 jours dure. */
    public const string REFRESH_TTL = 'P90D';

    private function __construct(
        private string $accessHash,
        private string $refreshHash,
        private string $linkId,
        private string $userId,
        private DateTimeImmutable $expiresAt,
        private DateTimeImmutable $refreshExpiresAt,
        private ?DateTimeImmutable $usedAt,
    ) {
    }

    public static function issue(string $accessHash, string $refreshHash, string $linkId, string $userId, DateTimeImmutable $now): self
    {
        return new self(
            $accessHash,
            $refreshHash,
            $linkId,
            $userId,
            $now->add(new DateInterval('PT' . self::ACCESS_TTL_SECONDS . 'S')),
            $now->add(new DateInterval(self::REFRESH_TTL)),
            null,
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            accessHash: $datas['access_hash'],
            refreshHash: $datas['refresh_hash'],
            linkId: $datas['link_id'],
            userId: $datas['user_id'],
            expiresAt: $datas['expires_at'],
            refreshExpiresAt: $datas['refresh_expires_at'],
            usedAt: $datas['used_at'],
        );
    }

    /**
     * La paire qui succède à celle-ci quand son jeton de rafraîchissement est échangé : même liaison, même compte, durées neuves. Le
     * marquage « consommée » de celle-ci est fait par le dépôt (`AccessGrantRepository::consumeRefresh`, atomique).
     */
    public function rotate(string $nextAccessHash, string $nextRefreshHash, DateTimeImmutable $now): self
    {
        return self::issue($nextAccessHash, $nextRefreshHash, $this->linkId, $this->userId, $now);
    }

    public function getId(): string
    {
        return $this->accessHash;
    }

    public function getRefreshHash(): string
    {
        return $this->refreshHash;
    }

    public function getLinkId(): string
    {
        return $this->linkId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRefreshExpiresAt(): DateTimeImmutable
    {
        return $this->refreshExpiresAt;
    }

    public function getUsedAt(): ?DateTimeImmutable
    {
        return $this->usedAt;
    }

    /** Le jeton de rafraîchissement de cette paire a déjà été échangé : le présenter encore est un rejeu. */
    public function isRefreshUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function isRefreshExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->refreshExpiresAt;
    }
}
