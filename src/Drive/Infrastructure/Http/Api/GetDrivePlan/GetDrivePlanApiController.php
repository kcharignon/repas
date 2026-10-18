<?php

namespace Repas\Drive\Infrastructure\Http\Api\GetDrivePlan;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanDTO;
use Repas\Drive\Application\Query\GetDrivePlan\GetDrivePlanQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\RequiresDrive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresDrive]
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
    #[OA\Response(response: 200, description: "Le plan, dans le magasin courant de l'utilisateur", content: new OA\JsonContent(ref: new Model(type: DrivePlanDTO::class)))]
    #[OA\Response(response: 403, description: 'La liste appartient à un autre utilisateur')]
    #[OA\Response(response: 404, description: 'Fonction drive coupée (message `DRIVE_DISABLED`) ou liste inconnue')]
    #[OA\Response(response: 409, description: "Pas de magasin enregistré (message `DRIVE_NOT_CONFIGURED`), ou la liste n'est pas envoyée au drive (message `SHOPPING_LIST_NOT_SENT`)")]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, string $shoppingListId): JsonResponse
    {
        /** @var DrivePlanDTO $plan */
        $plan = $this->queryBus->ask(new GetDrivePlanQuery($user->getId(), $shoppingListId));

        return new JsonResponse($plan);
    }
}
