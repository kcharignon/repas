<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListFavoriteRecipes;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListFavoriteRecipes\ListFavoriteRecipesQuery;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListFavoriteRecipesApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/recipes/favorites', name: 'api_list_favorite_recipes', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        summary: "Recettes que l'utilisateur authentifié a mises en favoris, triées par slug",
        description: "Toutes types confondus, secrètes comprises (chacune dit si elle l'est). La liste est vide tant qu'il n'a aucun favori : l'interface n'affiche alors pas le type « Favoris ».",
    )]
    #[OA\Tag(name: 'Recipes')]
    #[OA\Response(
        response: 200,
        description: 'Recettes favorites (collection `member`)',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: RecipeSummaryDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 3),
        ]),
    )]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var list<RecipeSummaryDTO> $recipes */
        $recipes = $this->queryBus->ask(new ListFavoriteRecipesQuery($user->getId()));

        return new JsonResponse(['member' => $recipes, 'totalItems' => count($recipes)]);
    }
}
