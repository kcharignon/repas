<?php

namespace Repas\User\Domain\Model;


use DateInterval;
use DateTimeImmutable;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * Le jeton du lien « mot de passe oublié » envoyé par email (ADR 0026). Le secret n'existe jamais ici : seul son hachage
 * est conservé, comme pour le jeton de confirmation d'adresse (ADR 0020) et le refresh token (ADR 0015).
 *
 * Un utilisateur n'a qu'un jeton à la fois (en émettre un nouveau remplace l'ancien) et il ne sert qu'une fois : la
 * réinitialisation le supprime. Il est volontairement distinct de `EmailVerificationToken` (ADR 0026) : un lien de
 * confirmation d'adresse ne doit jamais pouvoir changer un mot de passe.
 */
final class PasswordResetToken implements ModelInterface
{
    use ModelTrait;

    /** Durée de validité du lien. */
    public const string TTL = 'PT30M';
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
        return new self(
            id: $id,
            userId: $userId,
            tokenHash: $tokenHash,
            issuedAt: $now,
            expiresAt: $now->add(new DateInterval(self::TTL)),
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
