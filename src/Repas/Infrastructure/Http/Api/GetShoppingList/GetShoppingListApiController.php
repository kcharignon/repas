<?php

namespace Repas\Repas\Infrastructure\Http\Api\GetShoppingList;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\GetShoppingList\GetShoppingListQuery;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListDetailDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetShoppingListApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/{id}', name: 'api_get_shopping_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('SHOPPING_LIST_OWNER', 'id')]
    #[OA\Get(summary: "Détail d'une liste de courses de l'utilisateur : ingrédients par rayon, recettes par type")]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(
        response: 200,
        description: 'Détail de la liste',
        headers: [new OA\Header(header: 'ETag', description: 'Version de la liste entre guillemets : à renvoyer dans `If-Match` pour la modifier (ADR 0016)', schema: new OA\Schema(type: 'string', example: '"3"'))],
        content: new OA\JsonContent(ref: new Model(type: ShoppingListDetailDTO::class)),
    )]
    #[OA\Response(response: 403, description: "La liste appartient à un autre utilisateur")]
    #[OA\Response(response: 404, description: 'Liste inconnue')]
    public function __invoke(string $id): JsonResponse
    {
        /** @var ShoppingListDetailDTO $shoppingList */
        $shoppingList = $this->queryBus->ask(new GetShoppingListQuery($id));

        return new JsonResponse($shoppingList, headers: ['ETag' => EntityVersion::etag($shoppingList->version)]);
    }
}
