<?php

namespace Repas\Voice\Domain\Model;


use DateInterval;
use DateTimeImmutable;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * Le code d'autorisation que l'utilisateur donne à Alexa en autorisant la liaison (ADR 0029 décision 6, RFC 6749 4.1). Le code
 * n'existe jamais ici : seul son haché (`OpaqueToken::hash`) est conservé, il sert d'identifiant. Il vaut 10 minutes et ne sert
 * qu'une fois ; il est lié au client, à l'adresse de retour et au défi PKCE (S256) donnés à l'autorisation.
 */
final class AuthorizationCode implements ModelInterface
{
    use ModelTrait;

    /** Durée de validité du code. */
    public const string TTL = 'PT10M';

    private function __construct(
        private string $codeHash,
        private string $userId,
        private string $clientId,
        private string $redirectUri,
        private string $codeChallenge,
        private DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $usedAt,
        private ?string $linkId,
    ) {
    }

    public static function issue(string $codeHash, string $userId, string $clientId, string $redirectUri, string $codeChallenge, DateTimeImmutable $now): self
    {
        return new self($codeHash, $userId, $clientId, $redirectUri, $codeChallenge, $now->add(new DateInterval(self::TTL)), null, null);
    }

    public static function load(array $datas): self
    {
        return new self(
            codeHash: $datas['code_hash'],
            userId: $datas['user_id'],
            clientId: $datas['client_id'],
            redirectUri: $datas['redirect_uri'],
            codeChallenge: $datas['code_challenge'],
            expiresAt: $datas['expires_at'],
            usedAt: $datas['used_at'],
            linkId: $datas['link_id'],
        );
    }

    public function getId(): string
    {
        return $this->codeHash;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function getCodeChallenge(): string
    {
        return $this->codeChallenge;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?DateTimeImmutable
    {
        return $this->usedAt;
    }

    /** La liaison créée en échangeant ce code, une fois échangé. */
    public function getLinkId(): ?string
    {
        return $this->linkId;
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function belongsToClient(string $clientId): bool
    {
        return hash_equals($this->clientId, $clientId);
    }

    /**
     * L'échange présente la même adresse de retour que l'autorisation (comparaison exacte, RFC 6749 4.1.3) et un vérificateur dont le
     * SHA-256 est le défi gardé (PKCE S256). Le reste (code inconnu, expiré, déjà utilisé, client) est jugé avant par l'appelant.
     */
    public function accepts(string $redirectUri, string $codeVerifier): bool
    {
        return $redirectUri === $this->redirectUri && Pkce::verifies($codeVerifier, $this->codeChallenge);
    }
}
