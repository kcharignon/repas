<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListRecipesByType;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListRecipesByType\ListRecipesByTypeQuery;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeTypeRecipesDTO;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListRecipesByTypeApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/recipe-types/{slug}/recipes', name: 'api_list_recipes_by_type', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Recettes de l'utilisateur authentifié pour un type de recette, triées par slug")]
    #[OA\Tag(name: 'Recipes')]
    #[OA\Response(
        response: 200,
        description: 'Le type de recette et ses recettes (collection `member`)',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'recipeType', ref: new Model(type: RecipeTypeDTO::class)),
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: RecipeSummaryDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 4),
        ]),
    )]
    #[OA\Response(response: 404, description: 'Type de recette inconnu')]
    public function __invoke(string $slug, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var RecipeTypeRecipesDTO $recipesOfType */
        $recipesOfType = $this->queryBus->ask(new ListRecipesByTypeQuery($user->getId(), $slug));

        return new JsonResponse([
            'recipeType' => $recipesOfType->recipeType,
            'member' => $recipesOfType->recipes,
            'totalItems' => count($recipesOfType->recipes),
        ]);
    }
}
