<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListRecipeTypes;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListRecipeTypes\ListRecipeTypesQuery;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListRecipeTypesApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/recipe-types', name: 'api_list_recipe_types', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Liste des types de recette, dans l'ordre d'affichage")]
    #[OA\Tag(name: 'Recipe types')]
    #[OA\Response(
        response: 200,
        description: 'Liste des types de recette',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: RecipeTypeDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 4),
        ]),
    )]
    public function __invoke(): JsonResponse
    {
        /** @var list<RecipeTypeDTO> $recipeTypes */
        $recipeTypes = $this->queryBus->ask(new ListRecipeTypesQuery());

        return new JsonResponse(['member' => $recipeTypes, 'totalItems' => count($recipeTypes)]);
    }
}
