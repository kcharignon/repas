<?php

namespace Repas\User\Infrastructure\Http\Api\RegisterNewUser;


use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Plafonne l'inscription publique (`POST /api/v1/users`) par adresse IP (limiteur `registration`, config/packages/rate_limiter.yaml).
 *
 * C'est la seule route d'écriture sans jeton : aucun contrôle d'identité ne la protège, ni confirmation d'email ni captcha.
 * Au-delà du plafond : 429 + `Retry-After` (ADR 0008). Un listener plutôt que du code dans le controller, qui ne fait que
 * dispatcher la commande (ADR 0007 §3) ; il passe avant la validation du corps, donc les requêtes invalides comptent aussi.
 */
final readonly class RegisterNewUserRateLimitListener
{
    public function __construct(
        #[Autowire(service: 'limiter.registration')]
        private RateLimiterFactoryInterface $registrationLimiter,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function __invoke(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->get('_route') !== 'api_register_new_user') {
            return;
        }

        $limit = $this->registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many registrations, please try again later.');
        }
    }
}
