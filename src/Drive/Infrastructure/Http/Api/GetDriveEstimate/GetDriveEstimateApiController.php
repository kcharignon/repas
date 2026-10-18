<?php

namespace Repas\Drive\Infrastructure\Http\Api\GetDriveEstimate;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Query\GetDriveEstimate\DriveEstimateDTO;
use Repas\Drive\Application\Query\GetDriveEstimate\GetDriveEstimateQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\RequiresDrive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresDrive]
final class GetDriveEstimateApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/drive/estimates/{shoppingListId}', name: 'api_get_drive_estimate', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'shoppingListId')]
    #[OA\Get(summary: "Estimation du coût d'une liste d'après les derniers prix vus du produit préféré de chaque ligne (total, couverture, dates des prix, une ligne par ingrédient avec son statut) ; une estimation datée, jamais un prix garanti")]
    #[OA\Tag(name: 'Drive')]
    #[OA\Parameter(name: 'shoppingListId', in: 'path', required: true, description: 'Identifiant de la liste de courses (tout statut : lecture seule)', schema: new OA\Schema(type: 'string'), example: '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10')]
    #[OA\Response(response: 200, description: "L'estimation, dans le magasin courant de l'utilisateur", content: new OA\JsonContent(ref: new Model(type: DriveEstimateDTO::class)))]
    #[OA\Response(response: 403, description: 'La liste appartient à un autre utilisateur')]
    #[OA\Response(response: 404, description: 'Fonction drive coupée (message `DRIVE_DISABLED`) ou liste inconnue')]
    #[OA\Response(response: 409, description: 'Pas de magasin enregistré (message `DRIVE_NOT_CONFIGURED`)')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, string $shoppingListId): JsonResponse
    {
        /** @var DriveEstimateDTO $estimate */
        $estimate = $this->queryBus->ask(new GetDriveEstimateQuery($user->getId(), $shoppingListId));

        return new JsonResponse($estimate);
    }
}
