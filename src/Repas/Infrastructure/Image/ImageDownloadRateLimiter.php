<?php

namespace Repas\Repas\Infrastructure\Image;


use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Interface\ImageDownloadLimiter;
use Repas\Shared\Domain\Exception\RateLimitedException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Le plafond des téléchargements d'image par lien (limiteur `image_download` de config/packages/rate_limiter.yaml : 30 par heure). Un compteur PAR
 * UTILISATEUR (`user-<id>`), jamais par adresse : il suit le compte, quelle que soit l'adresse du client. L'état est dans le cache, aucune requête
 * SQL ; le compteur se lit et s'écrit sous le verrou de la fabrique des limiteurs (`BoundedLockFactory`). Un téléchargement refusé n'est pas compté.
 */
final readonly class ImageDownloadRateLimiter implements ImageDownloadLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.image_download')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function consume(string $userId): void
    {
        $limit = $this->limiter->create('user-' . $userId)->consume();
        if (!$limit->isAccepted()) {
            throw RateLimitedException::after(ImageException::RATE_LIMITED, $limit->getRetryAfter()->getTimestamp() - time());
        }
    }
}
