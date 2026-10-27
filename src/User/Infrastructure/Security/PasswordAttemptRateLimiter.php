<?php

namespace Repas\User\Infrastructure\Security;


use Repas\Shared\Domain\Exception\RateLimitedException;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\PasswordAttemptLimiter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Le plafond des mots de passe refusés d'un utilisateur connecté (limiteur `password_check` de config/packages/rate_limiter.yaml :
 * 5 par 15 minutes). Un compteur PAR UTILISATEUR (`user-<id>`), jamais par adresse : il suit le compte, quelle que soit l'adresse du
 * client. L'état est dans le cache, aucune requête SQL. Interroger le plafond ne consomme rien (`consume(0)`).
 */
final readonly class PasswordAttemptRateLimiter implements PasswordAttemptLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.password_check')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function assertAllowed(string $userId): void
    {
        $limit = $this->limiter->create('user-' . $userId)->consume(0);
        if (!$limit->isAccepted() || $limit->getRemainingTokens() === 0) {
            throw RateLimitedException::after(UserException::PASSWORD_RATE_LIMITED, $limit->getRetryAfter()->getTimestamp() - time());
        }
    }

    public function recordFailure(string $userId): void
    {
        $this->limiter->create('user-' . $userId)->consume();
    }
}
