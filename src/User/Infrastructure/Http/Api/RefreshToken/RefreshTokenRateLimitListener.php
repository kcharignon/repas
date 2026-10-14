<?php

namespace Repas\User\Infrastructure\Http\Api\RefreshToken;


use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Plafonne le rafraîchissement (`POST /api/auth/refresh`) par adresse IP (limiteur `refresh_token`, config/packages/rate_limiter.yaml) :
 * route publique, dont le seul secret est un cookie. Au-delà du plafond : 429 + `Retry-After` (ADR 0015 §9).
 */
final readonly class RefreshTokenRateLimitListener
{
    public function __construct(
        #[Autowire(service: 'limiter.refresh_token')]
        private RateLimiterFactoryInterface $refreshTokenLimiter,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function __invoke(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->get('_route') !== 'api_auth_refresh') {
            return;
        }

        $limit = $this->refreshTokenLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many refreshes, please try again later.');
        }
    }
}
