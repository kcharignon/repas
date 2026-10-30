<?php

namespace Repas\Drive\Infrastructure\Http\Api\GetDrivePlan;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanDTO;
use Repas\Drive\Application\Query\GetDrivePlan\GetDrivePlanQuery;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Infrastructure\Http\DriveRateLimit;
use Repas\Drive\Infrastructure\Http\RateLimitedPerUser;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\RequiresDrive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresDrive]
#[RateLimitedPerUser(DriveRateLimit::READ)]
final class GetDrivePlanApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/drive/plans/{shoppingListId}', name: 'api_get_drive_plan', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'shoppingListId')]
    #[OA\Get(summary: "Plan d'envoi d'une liste envoyée au drive : pour chaque ligne, le statut (`KNOWN`, `NEW`, `HAVE_AT_HOME`), la quantité demandée, les produits classés avec leur nombre de produits et les produits refusés")]
    #[OA\Tag(name: 'Drive')]
    #[OA\Parameter(name: 'shoppingListId', in: 'path', required: true, description: 'Identifiant de la liste de courses (statut `SENT`)', schema: new OA\Schema(type: 'string'), example: '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10')]
    #[OA\Parameter(name: 'retailer', in: 'query', required: false, description: "Enseigne du drive ; absente, l'enseigne par défaut", schema: new OA\Schema(type: 'string', enum: Retailer::IDS), example: 'leclerc')]
    #[OA\Response(response: 200, description: "Le plan, dans le magasin courant de l'utilisateur", content: new OA\JsonContent(ref: new Model(type: DrivePlanDTO::class)))]
    #[OA\Response(response: 403, description: 'La liste appartient à un autre utilisateur')]
    #[OA\Response(response: 404, description: 'Fonction drive coupée (message `DRIVE_DISABLED`), ou enseigne éteinte (message `DRIVE_RETAILER_DISABLED`, `DRIVE_RETAILERS`) ou liste inconnue')]
    #[OA\Response(response: 409, description: "Pas de magasin enregistré (message `DRIVE_NOT_CONFIGURED`), ou la liste n'est pas envoyée au drive (message `SHOPPING_LIST_NOT_SENT`)")]
    #[OA\Response(response: 422, description: 'Paramètre `retailer` inconnu')]
    #[OA\Response(response: 429, ref: '#/components/responses/DriveReadRateLimited')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, string $shoppingListId, #[MapQueryString(validationFailedStatusCode: 422)] ?GetDrivePlanRequestQuery $filter = null): JsonResponse
    {
        /** @var DrivePlanDTO $plan */
        $plan = $this->queryBus->ask(new GetDrivePlanQuery($user->getId(), $shoppingListId, $filter?->retailer));

        return new JsonResponse($plan);
    }
}
