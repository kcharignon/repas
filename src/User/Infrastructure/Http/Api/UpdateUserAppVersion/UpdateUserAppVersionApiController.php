<?php

namespace Repas\User\Infrastructure\Http\Api\UpdateUserAppVersion;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\User\Application\UpdateUserAppVersion\UpdateUserAppVersionCommand;
use Repas\User\Domain\Model\AppPlatform;
use Repas\User\Domain\Model\AppVersion;
use Repas\User\Infrastructure\AppVersion\LastSeenAppVersions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * L'application installée annonce sa version (DRV-637). Au plus une écriture par minute et par compte : `AppVersionRateLimitListener`
 * répond `204` sans rien écrire au-delà. Depuis DRV-758 la version est aussi retenue sur toute requête authentifiée qui porte les
 * en-têtes `X-App-Version` et `X-App-Platform` (`RecordAppVersionOnRequestListener`) ; ce `PUT` reste, compatible, et met à jour la
 * même mémoire (`LastSeenAppVersions`) pour que l'écouteur ne réécrive pas la version qu'il vient de poser.
 */
final class UpdateUserAppVersionApiController extends AbstractController
{
    public const string ROUTE = 'api_update_user_app_version';

    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly LastSeenAppVersions $lastSeenAppVersions,
    ) {
    }

    #[Route(path: '/api/v1/users/me/app-version', name: self::ROUTE, methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(
        summary: "Retient la version de l'application installée pour l'utilisateur connecté",
        description: "Une ligne par compte et par plateforme, remplacée à chaque appel (idempotent). Seule la version est retenue, avec l'heure : aucun identifiant d'appareil, aucun modèle de téléphone. Au plus une écriture par minute et par compte : au-delà, la réponse reste `204` et rien n'est écrit. Ce `PUT` n'est plus nécessaire à l'application : toute requête authentifiée de `/api/v1/*` qui porte les en-têtes `X-App-Version` (trois nombres séparés par des points) et `X-App-Platform` (`android`) retient la version après la réponse, avec le même plafond (en-têtes absents, mal formés ou inconnus : ignorés sans erreur). La version ne sert qu'à informer (`appUpdate` de `GET /api/v1/me`, liste des comptes de l'administration), jamais à accorder un droit.",
    )]
    #[OA\Tag(name: 'Current user')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateUserAppVersionPayload::class)))]
    #[OA\Response(response: 204, description: 'Version retenue (ou ignorée sans erreur : une version a déjà été retenue pour ce compte dans la minute)')]
    #[OA\Response(response: 422, description: "Corps invalide : plateforme autre que `android`, version qui n'est pas trois nombres séparés par des points, ou de plus de 20 caractères")]
    public function __invoke(
        #[CurrentUser] AuthenticatedUser $user,
        #[MapRequestPayload(validationFailedStatusCode: 422)] UpdateUserAppVersionPayload $payload,
    ): Response {
        $platform = AppPlatform::from($payload->platform);
        $this->commandBus->dispatch(new UpdateUserAppVersionCommand(
            userId: $user->getId(),
            platform: $platform,
            version: $payload->version,
        ));
        $this->lastSeenAppVersions->remember($user->getId(), $platform, AppVersion::fromString($payload->version));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
