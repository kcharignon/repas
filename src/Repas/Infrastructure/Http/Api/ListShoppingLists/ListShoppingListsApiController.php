<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListShoppingLists;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListShoppingLists\ListShoppingListsQuery;
use Repas\Repas\Application\Query\ListShoppingLists\ShoppingListDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListShoppingListsApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists', name: 'api_list_shopping_lists', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Listes de courses de l'utilisateur authentifié, de la plus récente à la plus ancienne")]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(
        response: 200,
        description: 'Listes de courses',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: ShoppingListDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 3),
        ]),
    )]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var list<ShoppingListDTO> $shoppingLists */
        $shoppingLists = $this->queryBus->ask(new ListShoppingListsQuery($user->getId()));

        return new JsonResponse(['member' => $shoppingLists, 'totalItems' => count($shoppingLists)]);
    }
}
