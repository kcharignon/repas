<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListShoppingListTypes;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListShoppingListTypes\ListShoppingListTypesQuery;
use Repas\Repas\Application\Query\ListShoppingListTypes\ShoppingListTypeSummaryDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListShoppingListTypesApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-list-types', name: 'api_list_shopping_list_types', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        summary: "Types de liste de courses de l'utilisateur authentifié, triés par date de création",
        description: "Un type est un nom que l'utilisateur crée (« Boucherie », « Marché ») ; les produits de ses rayons vont dans la liste en cours de ce type (ADR 0036). Propres à chaque compte, administrateur compris : jamais ceux d'un autre. La liste est vide tant que l'utilisateur n'en a créé aucun : rien ne change alors pour lui.",
    )]
    #[OA\Tag(name: 'Shopping list types')]
    #[OA\Response(
        response: 200,
        description: 'Types de liste (collection `member`)',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: ShoppingListTypeSummaryDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 2),
        ]),
    )]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var list<ShoppingListTypeSummaryDTO> $types */
        $types = $this->queryBus->ask(new ListShoppingListTypesQuery($user->getId()));

        return new JsonResponse(['member' => $types, 'totalItems' => count($types)]);
    }
}
