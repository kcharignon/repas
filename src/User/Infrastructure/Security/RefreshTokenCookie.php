<?php

namespace Repas\User\Infrastructure\Security;


use Repas\User\Domain\Model\RefreshToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le cookie qui porte le refresh token du SPA (ADR 0015 §1) : `HttpOnly`, `SameSite=Strict`, limité à `/api/auth`, donc
 * envoyé au seul refresh et à la déconnexion. `Secure` partout sauf en test (le client de test parle en HTTP).
 */
final readonly class RefreshTokenCookie
{
    public const string NAME = 'repas_refresh';
    public const string PATH = '/api/auth';

    public function __construct(
        #[Autowire('%app.refresh_cookie_secure%')]
        private bool $secure,
    ) {
    }

    /**
     * Un secret neuf : 256 bits aléatoires, en hexadécimal.
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function read(Request $request): ?string
    {
        $secret = $request->cookies->get(self::NAME);

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function create(string $secret): Cookie
    {
        $ttl = (new \DateTimeImmutable('@0'))->add(new \DateInterval(RefreshToken::TTL))->getTimestamp();

        return Cookie::create(self::NAME)
            ->withValue($secret)
            ->withExpires(time() + $ttl)
            ->withPath(self::PATH)
            ->withSecure($this->secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    public function clear(): Cookie
    {
        return Cookie::create(self::NAME)
            ->withValue('')
            ->withExpires(1)
            ->withPath(self::PATH)
            ->withSecure($this->secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }
}
