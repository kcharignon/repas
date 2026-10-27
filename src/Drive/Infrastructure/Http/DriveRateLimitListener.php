<?php

namespace Repas\Drive\Infrastructure\Http;


use Psr\Clock\ClockInterface;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\ClientAddressKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Plafonne PAR UTILISATEUR les routes du drive marquées `#[RateLimitedPerUser]` (DRV-615, constat C2 de la revue de sécurité) :
 * un compte inscrit peut remplir les tables communes du drive, et l'API authentifiée n'avait aucun plafond de débit.
 *
 * - Le compteur est celui de l'utilisateur du jeton (`AuthenticatedUser::getId()`), jamais celui de l'adresse IP : la famille
 *   partage une adresse, un visiteur change la sienne. Sans utilisateur (route mal protégée), repli sur l'adresse IP plutôt que
 *   sur aucun plafond.
 * - Passe APRÈS `DriveEnabledListener` (priorité plus basse) : interrupteur coupé, `404 DRIVE_DISABLED` et aucun jeton consommé.
 *   Passe avant la résolution des arguments : une requête au corps invalide compte aussi, comme pour les autres limiteurs.
 * - Au-delà du plafond : `429` + `Retry-After` en secondes, message `DRIVE_RATE_LIMITED` (`ApiExceptionListener` met la
 *   réponse en forme). Les requêtes refusées ne sont pas comptées : attendre `Retry-After` suffit.
 * - Aucune requête SQL : l'état de la fenêtre glissante est dans le cache (`cache.rate_limiter`, fichiers de `var/cache` ; une
 *   seule machine, tous les workers FrankenPHP voient les mêmes fichiers). Un vidage du cache remet les compteurs à zéro.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: -10)]
final readonly class DriveRateLimitListener
{
    public function __construct(
        #[Autowire(service: 'limiter.drive_write')]
        private RateLimiterFactoryInterface $writeLimiter,
        #[Autowire(service: 'limiter.drive_read')]
        private RateLimiterFactoryInterface $readLimiter,
        private TokenStorageInterface $tokenStorage,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        $attributes = $event->getAttributes(RateLimitedPerUser::class);
        if ($attributes === []) {
            return;
        }

        $key = $this->keyOf($event->getRequest());
        foreach ($attributes as $attribute) {
            $limit = $this->factoryFor($attribute->limit)->create($key)->consume();
            if ($limit->isAccepted()) {
                continue;
            }

            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());

            throw new TooManyRequestsHttpException($retryAfter, DriveException::DRIVE_RATE_LIMITED, DriveException::rateLimited());
        }
    }

    private function keyOf(Request $request): string
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof AuthenticatedUser
            ? 'user-' . $user->getId()
            : 'ip-' . ClientAddressKey::of($request->getClientIp());
    }

    private function factoryFor(DriveRateLimit $limit): RateLimiterFactoryInterface
    {
        return match ($limit) {
            DriveRateLimit::WRITE => $this->writeLimiter,
            DriveRateLimit::READ => $this->readLimiter,
        };
    }
}
