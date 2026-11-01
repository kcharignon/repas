<?php

namespace Repas\User\Infrastructure\EventListener;


use Psr\Log\LoggerInterface;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\User\Application\UpdateUserAppVersion\UpdateUserAppVersionCommand;
use Repas\User\Domain\Model\AppPlatform;
use Repas\User\Domain\Model\AppVersion;
use Repas\User\Infrastructure\AppVersion\LastSeenAppVersions;
use Repas\User\Infrastructure\Http\Api\UpdateUserAppVersion\UpdateUserAppVersionApiController;
use Closure;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Throwable;

/**
 * La version de l'application est retenue sur TOUTE requête authentifiée de `/api/v1/*` qui la porte (DRV-758, amendement de
 * DRV-637) : en-têtes `X-App-Version` (trois nombres séparés par des points) et `X-App-Platform` (`android`). `PUT
 * /api/v1/users/me/app-version` reste en place, compatible.
 *
 * - **Après la réponse** (`kernel.terminate`) : ni le contenu ni le temps de réponse ne changent ; une écriture qui échoue est
 *   journalisée (classe et endroit, jamais le message) et n'a aucun effet pour l'utilisateur.
 * - **Zéro requête SQL sur le chemin courant** : la dernière version écrite est en mémoire (`LastSeenAppVersions`) ; on n'écrit
 *   que si la version vue est PLUS RÉCENTE que celle-là (ou si la mémoire est vide), et au plus une fois par minute et par compte
 *   (le limiteur `app_version`, le même que celui du `PUT` : jeton consommé seulement quand une écriture est due). Refusée par le
 *   limiteur, la version est simplement reprise à la requête suivante. Une version plus ancienne que la mémoire n'écrit rien :
 *   deux appareils du même compte ne se disputent pas la ligne ; la mémoire expire au bout d'une heure.
 * - **Information non fiable** : un en-tête absent, mal formé ou d'une plateforme inconnue est ignoré en silence (jamais une
 *   erreur) ; le compte vient du jeton, jamais d'un en-tête ; une route publique (aucun jeton) et un compte désactivé (ADR 0021)
 *   n'écrivent rien ; la version n'accorde aucun droit.
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final readonly class RecordAppVersionOnRequestListener
{
    public const string VERSION_HEADER = 'X-App-Version';
    public const string PLATFORM_HEADER = 'X-App-Platform';

    private const string API_PREFIX = '/api/v1/';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        // Fermetures de service : l'écouteur est construit à la fin de CHAQUE requête, le cache, le bus et le limiteur ne sont pris
        // que si une version est à retenir (et un test peut encore remplacer le bus après une première requête)
        /** @var Closure(): LastSeenAppVersions $lastSeenAppVersions */
        #[AutowireServiceClosure(LastSeenAppVersions::class)]
        private Closure $lastSeenAppVersions,
        /** @var Closure(): CommandBusInterface $commandBus */
        #[AutowireServiceClosure(CommandBusInterface::class)]
        private Closure $commandBus,
        /** @var Closure(): RateLimiterFactoryInterface $appVersionLimiter */
        #[AutowireServiceClosure('limiter.app_version')]
        private Closure $appVersionLimiter,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $versionHeader = $request->headers->get(self::VERSION_HEADER);
        // Le plus courant : pas d'en-tête (navigateur), rien d'autre n'est lu
        if ($versionHeader === null || !str_starts_with($request->getPathInfo(), self::API_PREFIX)) {
            return;
        }
        // Le `PUT` retient lui-même la version qu'il porte
        if ($request->attributes->get('_route') === UpdateUserAppVersionApiController::ROUTE) {
            return;
        }

        $platform = AppPlatform::tryFrom($request->headers->get(self::PLATFORM_HEADER) ?? '');
        $version = AppVersion::tryFromString($versionHeader);
        $user = $this->tokenStorage->getToken()?->getUser();
        if ($platform === null || $version === null || !$user instanceof AuthenticatedUser || $user->isDisabled()) {
            return;
        }

        try {
            $this->record($user->getId(), $platform, $version);
        } catch (Throwable $exception) {
            // Jamais un échec pour l'utilisateur, jamais le message (une version et un compte peuvent y figurer)
            $this->logger->warning(sprintf(
                'Version de l\'application non retenue (%s à %s:%d)',
                $exception::class,
                basename($exception->getFile()),
                $exception->getLine(),
            ));
        }
    }

    private function record(string $userId, AppPlatform $platform, AppVersion $version): void
    {
        $known = ($this->lastSeenAppVersions)()->of($userId, $platform);
        if ($known !== null && !$known->isOlderThan($version)) {
            return;
        }
        if (!($this->appVersionLimiter)()->create('user-' . $userId)->consume()->isAccepted()) {
            return;
        }

        ($this->commandBus)()->dispatch(new UpdateUserAppVersionCommand($userId, $platform, $version->toString()));
        ($this->lastSeenAppVersions)()->remember($userId, $platform, $version);
    }
}
