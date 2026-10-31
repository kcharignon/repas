<?php

namespace Repas\Repas\Infrastructure\Http\Api\GetShoppingListType;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\GetShoppingListType\GetShoppingListTypeQuery;
use Repas\Repas\Application\Query\GetShoppingListType\ShoppingListTypeDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetShoppingListTypeApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-list-types/{id}', name: 'api_get_shopping_list_type', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        summary: "Un type de liste de courses de l'utilisateur, avec ses rayons et ses produits à part",
        description: "Les rayons rattachés au type (avec leur nom) et les produits à part : ceux de l'utilisateur dont le type est choisi à la main, avec leur rayon. Le type d'un autre utilisateur, administrateur compris, est inconnu (`404`, la même réponse qu'un identifiant inconnu).",
    )]
    #[OA\Tag(name: 'Shopping list types')]
    #[OA\Response(response: 200, description: 'Le type de liste', content: new OA\JsonContent(ref: new Model(type: ShoppingListTypeDTO::class)))]
    #[OA\Response(response: 404, description: "Type inconnu, ou à un autre utilisateur")]
    public function __invoke(string $id, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        return new JsonResponse($this->queryBus->ask(new GetShoppingListTypeQuery($id, $user->getId())));
    }
}
