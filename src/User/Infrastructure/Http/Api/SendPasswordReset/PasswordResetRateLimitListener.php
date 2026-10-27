<?php

namespace Repas\User\Infrastructure\Http\Api\SendPasswordReset;


use Repas\Shared\Infrastructure\Http\ClientAddressKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Plafonne par adresse IP les deux routes publiques du mot de passe oublié (limiteur `password_reset`,
 * config/packages/rate_limiter.yaml) : la demande du lien (un email part à chaque demande acceptée) et la réinitialisation.
 * Au-delà du plafond : 429 + `Retry-After` (ADR 0008, ADR 0026). Un listener plutôt que du code dans les controllers,
 * qui ne font que dispatcher la commande (ADR 0007 §3).
 */
final readonly class PasswordResetRateLimitListener
{
    private const array ROUTES = ['api_send_password_reset', 'api_reset_password'];

    public function __construct(
        #[Autowire(service: 'limiter.password_reset')]
        private RateLimiterFactoryInterface $passwordResetLimiter,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function __invoke(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!in_array($request->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }

        $limit = $this->passwordResetLimiter->create(ClientAddressKey::of($request->getClientIp()))->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many requests, please try again later.');
        }
    }
}
