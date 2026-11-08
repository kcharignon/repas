<?php

namespace Repas\Repas\Infrastructure\Marmiton;


use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Interface\MarmitonImportLimiter;
use Repas\Shared\Domain\Exception\RateLimitedException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Le plafond des lectures de page Marmiton (limiteur `marmiton_import` de config/packages/rate_limiter.yaml : 20 par heure). Un compteur PAR
 * UTILISATEUR (`user-<id>`), jamais par adresse : il suit le compte, quelle que soit l'adresse du client. L'état est dans le cache, aucune requête
 * SQL ; le compteur se lit et s'écrit sous le verrou de la fabrique des limiteurs (`BoundedLockFactory`). Une lecture refusée n'est pas comptée.
 */
final readonly class MarmitonImportRateLimiter implements MarmitonImportLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.marmiton_import')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function consume(string $userId): void
    {
        $limit = $this->limiter->create('user-' . $userId)->consume();
        if (!$limit->isAccepted()) {
            throw RateLimitedException::after(MarmitonImportException::RATE_LIMITED, $limit->getRetryAfter()->getTimestamp() - time());
        }
    }
}
