<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListOpenShoppingLists;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListOpenShoppingLists\ListOpenShoppingListsQuery;
use Repas\Repas\Application\Query\ListOpenShoppingLists\OpenShoppingListDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Toutes les listes en cours de l'utilisateur (ADR 0036 décision 8). La route précède `GET /shopping-lists/{id}` (`priority`), sinon « open »
 * serait lu comme un identifiant de liste.
 */
final class ListOpenShoppingListsApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/open', name: 'api_list_open_shopping_lists', methods: ['GET'], priority: 10)]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        summary: "Listes en cours de l'utilisateur authentifié : la liste principale d'abord, puis une par type de liste",
        description: "Une liste en cours (`ACTIVE`) au plus par type et une pour la liste principale (ADR 0036). Les types sont rangés par date de création, puis identifiant. Un compte sans type de liste reçoit au plus sa liste principale. Une liste en pause, envoyée au drive ou terminée n'y figure pas (`GET /api/v1/shopping-lists`).",
    )]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(
        response: 200,
        description: 'Listes en cours',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: OpenShoppingListDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 2),
        ]),
    )]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var list<OpenShoppingListDTO> $lists */
        $lists = $this->queryBus->ask(new ListOpenShoppingListsQuery($user->getId()));

        return new JsonResponse(['member' => $lists, 'totalItems' => count($lists)]);
    }
}
