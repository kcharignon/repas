<?php

namespace Repas\User\Domain\Model;


use DateInterval;
use DateTimeImmutable;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * Un jeton de rafraîchissement du SPA (ADR 0015). Le secret n'existe jamais ici : seul son hachage est conservé.
 *
 * Les jetons issus d'une même connexion forment une famille. Un jeton ne sert qu'une fois (`rotate()`) : le présenter
 * une deuxième fois, au-delà d'un court délai de grâce, révèle un vol et fait supprimer toute la famille.
 */
final class RefreshToken implements ModelInterface
{
    use ModelTrait;

    /** Durée glissante d'un jeton. */
    public const string TTL = 'P30D';
    /** Durée absolue d'une famille, de la connexion à la dernière utilisation possible. */
    public const string FAMILY_TTL = 'P90D';
    /** Secondes pendant lesquelles un jeton déjà utilisé est refusé sans condamner la famille (deux onglets en course). */
    public const int REUSE_GRACE_SECONDS = 10;
    /** Connexions simultanées conservées par utilisateur. */
    public const int MAX_FAMILIES_PER_USER = 10;

    private function __construct(
        private string $id,
        private string $userId,
        private string $familyId,
        private string $tokenHash,
        private int $credentialsVersion,
        private DateTimeImmutable $issuedAt,
        private DateTimeImmutable $expiresAt,
        private DateTimeImmutable $familyExpiresAt,
        private ?DateTimeImmutable $usedAt,
    ) {
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Premier jeton d'une famille, émis à la connexion.
     */
    public static function startFamily(
        string $id,
        string $familyId,
        string $userId,
        string $tokenHash,
        int $credentialsVersion,
        DateTimeImmutable $now,
    ): self {
        return new self(
            id: $id,
            userId: $userId,
            familyId: $familyId,
            tokenHash: $tokenHash,
            credentialsVersion: $credentialsVersion,
            issuedAt: $now,
            expiresAt: $now->add(new DateInterval(self::TTL)),
            familyExpiresAt: $now->add(new DateInterval(self::FAMILY_TTL)),
            usedAt: null,
        );
    }

    /**
     * Consomme ce jeton et renvoie son successeur, de la même famille et de la même version des identifiants. Le
     * successeur n'est jamais valable plus longtemps que la famille.
     */
    public function rotate(string $nextId, string $nextTokenHash, DateTimeImmutable $now): self
    {
        $this->usedAt = $now;
        $nextExpiresAt = $now->add(new DateInterval(self::TTL));

        return new self(
            id: $nextId,
            userId: $this->userId,
            familyId: $this->familyId,
            tokenHash: $nextTokenHash,
            credentialsVersion: $this->credentialsVersion,
            issuedAt: $now,
            expiresAt: min($nextExpiresAt, $this->familyExpiresAt),
            familyExpiresAt: $this->familyExpiresAt,
            usedAt: null,
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            userId: $datas['user_id'],
            familyId: $datas['family_id'],
            tokenHash: $datas['token_hash'],
            credentialsVersion: $datas['credentials_version'],
            issuedAt: $datas['issued_at'],
            expiresAt: $datas['expires_at'],
            familyExpiresAt: $datas['family_expires_at'],
            usedAt: $datas['used_at'],
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getFamilyId(): string
    {
        return $this->familyId;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getCredentialsVersion(): int
    {
        return $this->credentialsVersion;
    }

    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getFamilyExpiresAt(): DateTimeImmutable
    {
        return $this->familyExpiresAt;
    }

    public function getUsedAt(): ?DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt || $now >= $this->familyExpiresAt;
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    /**
     * Ce jeton vient d'être utilisé (moins de `REUSE_GRACE_SECONDS`) : le présenter encore est une course entre deux
     * onglets, pas un rejeu.
     */
    public function wasJustUsed(DateTimeImmutable $now): bool
    {
        return $this->usedAt !== null && $now->getTimestamp() - $this->usedAt->getTimestamp() <= self::REUSE_GRACE_SECONDS;
    }
}
