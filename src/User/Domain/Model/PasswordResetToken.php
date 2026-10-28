<?php

namespace Repas\User\Domain\Model;


use DateInterval;
use DateTimeImmutable;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * Le jeton du lien « mot de passe oublié » envoyé par email (ADR 0026), et celui du lien de bienvenue d'une inscription (ADR 0020
 * amendé) : l'un et l'autre mènent au même écran de choix du mot de passe et ne diffèrent que par leur durée. Le secret n'existe jamais ici : seul son hachage
 * est conservé, comme pour le jeton de confirmation d'adresse (ADR 0020) et le refresh token (ADR 0015).
 *
 * Un utilisateur n'a qu'un jeton à la fois (en émettre un nouveau remplace l'ancien) et il ne sert qu'une fois : la
 * réinitialisation le supprime. Il est volontairement distinct de `EmailVerificationToken` (ADR 0026) : un lien de
 * confirmation d'adresse ne doit jamais pouvoir changer un mot de passe.
 */
final class PasswordResetToken implements ModelInterface
{
    use ModelTrait;

    /** Durée de validité du lien « mot de passe oublié ». */
    public const string TTL = 'PT30M';
    /** Durée de validité du lien de bienvenue (inscription) : celle du lien de confirmation d'adresse qu'il remplace (ADR 0020), pas 30 minutes. */
    public const string WELCOME_TTL = 'PT24H';
    /** Délai minimal entre deux envois pour un même compte : une demande plus rapprochée est ignorée (pas de mitraillage de la boîte). */
    public const int RESEND_COOLDOWN_SECONDS = 60;

    private function __construct(
        private string $id,
        private string $userId,
        private string $tokenHash,
        private DateTimeImmutable $issuedAt,
        private DateTimeImmutable $expiresAt,
    ) {
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function issue(string $id, string $userId, string $tokenHash, DateTimeImmutable $now): self
    {
        return self::issueLasting(self::TTL, $id, $userId, $tokenHash, $now);
    }

    /**
     * Lien de bienvenue d'une inscription : il fait choisir le mot de passe d'un compte tout juste créé (ou toujours en attente), valable
     * `WELCOME_TTL`.
     */
    public static function issueWelcome(string $id, string $userId, string $tokenHash, DateTimeImmutable $now): self
    {
        return self::issueLasting(self::WELCOME_TTL, $id, $userId, $tokenHash, $now);
    }

    private static function issueLasting(string $ttl, string $id, string $userId, string $tokenHash, DateTimeImmutable $now): self
    {
        return new self(
            id: $id,
            userId: $userId,
            tokenHash: $tokenHash,
            issuedAt: $now,
            expiresAt: $now->add(new DateInterval($ttl)),
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            userId: $datas['user_id'],
            tokenHash: $datas['token_hash'],
            issuedAt: $datas['issued_at'],
            expiresAt: $datas['expires_at'],
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

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /**
     * Le jeton date de moins de `RESEND_COOLDOWN_SECONDS` : une nouvelle demande est trop tôt.
     */
    public function wasIssuedRecently(DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() - $this->issuedAt->getTimestamp() < self::RESEND_COOLDOWN_SECONDS;
    }
}
