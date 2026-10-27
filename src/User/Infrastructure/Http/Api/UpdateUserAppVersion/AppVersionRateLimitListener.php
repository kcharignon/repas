<?php

namespace Repas\User\Infrastructure\Http\Api\UpdateUserAppVersion;


use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\ClientAddressKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Au plus une écriture de la version de l'application par minute et PAR UTILISATEUR (`PUT /api/v1/users/me/app-version`,
 * limiteur `app_version` de config/packages/rate_limiter.yaml, DRV-637).
 *
 * Mêmes conventions que les autres plafonds par utilisateur (DRV-615) : compteur de l'utilisateur du jeton (`user-<id>`, jamais
 * l'adresse IP seule), fenêtre glissante, état dans le cache (aucune requête SQL). Deux différences, voulues :
 * - au-delà du plafond, **pas d'erreur** : la réponse reste `204` et la commande n'est pas envoyée (le contrôleur est remplacé).
 *   Ce n'est pas une protection contre un abus que l'appelant doit voir, mais l'assurance qu'un démarrage répété de l'application
 *   n'écrit pas plus d'une fois par minute ; le front ignore de toute façon la réponse ;
 * - le jeton n'est consommé **qu'après** la sécurité et la validation du corps (`kernel.controller_arguments`, priorité plus basse
 *   que celle de `RequestPayloadValueResolver`, -10100) : un corps invalide reçoit son `422` et ne prive pas l'appel valide qui
 *   suit de son écriture. Un corps invalide ne coûte aucune requête SQL.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS, priority: -10200)]
final readonly class AppVersionRateLimitListener
{
    public function __construct(
        #[Autowire(service: 'limiter.app_version')]
        private RateLimiterFactoryInterface $appVersionLimiter,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->get('_route') !== UpdateUserAppVersionApiController::ROUTE) {
            return;
        }

        if ($this->appVersionLimiter->create($this->keyOf($request))->consume()->isAccepted()) {
            return;
        }

        // Déjà écrit dans la minute : rien à faire, et rien à dire à l'appelant
        $event->setController(static fn(): Response => new Response(status: Response::HTTP_NO_CONTENT), []);
        $event->setArguments([]);
    }

    private function keyOf(Request $request): string
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof AuthenticatedUser
            ? 'user-' . $user->getId()
            : 'ip-' . ClientAddressKey::of($request->getClientIp());
    }
}
