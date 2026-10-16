<?php

namespace Repas\User\Infrastructure\Http\Api\SendEmailVerification;


use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Plafonne par adresse IP les deux routes publiques de confirmation d'adresse (limiteur `email_verification`,
 * config/packages/rate_limiter.yaml) : le renvoi du lien (un email part à chaque demande acceptée) et la confirmation.
 * Au-delà du plafond : 429 + `Retry-After` (ADR 0008, ADR 0020). Un listener plutôt que du code dans les controllers,
 * qui ne font que dispatcher la commande (ADR 0007 §3).
 */
final readonly class EmailVerificationRateLimitListener
{
    private const array ROUTES = ['api_send_email_verification', 'api_verify_email'];

    public function __construct(
        #[Autowire(service: 'limiter.email_verification')]
        private RateLimiterFactoryInterface $emailVerificationLimiter,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function __invoke(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!in_array($request->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }

        $limit = $this->emailVerificationLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many requests, please try again later.');
        }
    }
}
