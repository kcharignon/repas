<?php

namespace Repas\User\Infrastructure\Security;


use Closure;
use Repas\Shared\Domain\Exception\RateLimitedException;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\PasswordAttemptLimiter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Le plafond des mots de passe refusés d'un utilisateur (limiteur `password_check` de config/packages/rate_limiter.yaml : 5 par
 * 15 minutes). Un compteur PAR UTILISATEUR (`user-<id>`), jamais par adresse : il suit le compte, quelle que soit l'adresse du client.
 * L'état est dans le cache, aucune requête SQL.
 *
 * Lire le plafond, comparer et compter se font sous un verrou propre à l'utilisateur (`lock.factory`, un verrou par utilisateur : la
 * comparaison d'un mot de passe dure des centaines de millisecondes, ce qui n'est pas la durée d'un verrou de compteur). Les demandes
 * simultanées d'un même utilisateur se suivent : au plus cinq comparaisons échouent par fenêtre, et un mot de passe juste ne consomme
 * rien. Interroger le plafond ne consomme rien (`consume(0)`). Le verrou du compteur, pris dedans, est un autre verrou.
 */
final readonly class PasswordAttemptRateLimiter implements PasswordAttemptLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.password_check')]
        private RateLimiterFactoryInterface $limiter,
        #[Autowire(service: 'lock.factory')]
        private LockFactory $locks,
    ) {
    }

    public function attempt(string $userId, Closure $matches): bool
    {
        $lock = $this->locks->createLock('password-check-' . $userId);
        $lock->acquire(true);

        try {
            $counter = $this->limiter->create('user-' . $userId);
            $limit = $counter->consume(0);
            if (!$limit->isAccepted() || $limit->getRemainingTokens() === 0) {
                throw RateLimitedException::after(UserException::PASSWORD_RATE_LIMITED, $limit->getRetryAfter()->getTimestamp() - time());
            }

            if ($matches()) {
                return true;
            }

            $counter->consume();

            return false;
        } finally {
            $lock->release();
        }
    }
}
