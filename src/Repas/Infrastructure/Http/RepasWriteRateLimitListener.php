<?php

namespace Repas\Repas\Infrastructure\Http;


use Closure;
use Psr\Clock\ClockInterface;
use Repas\Shared\Domain\Exception\RateLimitedException;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\ClientAddressKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Plafonne PAR UTILISATEUR les écritures de l'API de `Repas` (recettes, ingrédients, listes de courses, repas, conversions, unités…) :
 * toute requête `POST`, `PUT`, `PATCH` ou `DELETE` servie par un controller de `Repas\Repas\Infrastructure\Http\Api`, sans avoir à
 * marquer chaque route (une route ajoutée plus tard est plafonnée d'office). Les lectures ne sont jamais comptées.
 *
 * - Le plafond est généreux (`repas_write`, 300 par minute, soit cinq par seconde) : cocher quarante lignes d'une liste d'affilée, ou toucher
 *   plusieurs fois « + » sur un ingrédient, reste très en deçà. Il n'arrête qu'un flot (boucle, script).
 * - Le compteur est celui de l'utilisateur du jeton (`AuthenticatedUser::getId()`), jamais celui de l'adresse IP : la famille partage une
 *   adresse. Sans utilisateur (route mal protégée), repli sur l'adresse plutôt que sur aucun plafond.
 * - Passe avant la résolution des arguments : une requête au corps invalide compte aussi. Une requête sans jeton valide est refusée avant
 *   (`401`) et ne consomme rien.
 * - Au-delà du plafond : `429` + `Retry-After` en secondes, message `WRITE_RATE_LIMITED`. Les requêtes refusées ne sont pas comptées :
 *   attendre `Retry-After` suffit. Les écritures du drive, des comptes et de la commande vocale ont leurs propres plafonds.
 * - Aucune requête SQL : l'état de la fenêtre glissante est dans le cache (`cache.rate_limiter`).
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: -10)]
final readonly class RepasWriteRateLimitListener
{
    public const string MESSAGE = 'WRITE_RATE_LIMITED';

    /** Les controllers dont les écritures sont plafonnées. */
    public const string CONTROLLER_NAMESPACE = 'Repas\\Repas\\Infrastructure\\Http\\Api\\';

    public function __construct(
        #[Autowire(service: 'limiter.repas_write')]
        private RateLimiterFactoryInterface $writeLimiter,
        private TokenStorageInterface $tokenStorage,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!self::limits(self::controllerClass($event->getController()), $request->getMethod())) {
            return;
        }

        $limit = $this->writeLimiter->create($this->keyOf($request))->consume();
        if ($limit->isAccepted()) {
            return;
        }

        throw RateLimitedException::after(self::MESSAGE, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    /**
     * Cette requête consomme-t-elle un jeton ? Une écriture (méthode qui n'est pas une lecture) d'un controller de `Repas`.
     */
    public static function limits(?string $controllerClass, string $method): bool
    {
        return $controllerClass !== null
            && str_starts_with($controllerClass, self::CONTROLLER_NAMESPACE)
            && !in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * La classe du controller appelé (méthode d'une classe, controller invocable), `null` pour une fonction anonyme.
     */
    private static function controllerClass(mixed $controller): ?string
    {
        return match (true) {
            is_array($controller) && is_object($controller[0] ?? null) => $controller[0]::class,
            is_object($controller) && !$controller instanceof Closure => $controller::class,
            default => null,
        };
    }

    private function keyOf(Request $request): string
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof AuthenticatedUser
            ? 'user-' . $user->getId()
            : 'ip-' . ClientAddressKey::of($request->getClientIp());
    }
}
