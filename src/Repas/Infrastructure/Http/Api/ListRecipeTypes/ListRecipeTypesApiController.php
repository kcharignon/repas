<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListRecipeTypes;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListRecipeTypes\ListRecipeTypesQuery;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeCountDTO;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListRecipeTypesApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/recipe-types', name: 'api_list_recipe_types', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Liste des types de recette, dans l'ordre d'affichage, avec le nombre de recettes de la personne dans chacun")]
    #[OA\Tag(name: 'Recipe types')]
    #[OA\Response(
        response: 200,
        description: 'Liste des types de recette',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: RecipeTypeCountDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 4),
        ]),
    )]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var list<RecipeTypeCountDTO> $recipeTypes */
        $recipeTypes = $this->queryBus->ask(new ListRecipeTypesQuery($user->getId()));

        return new JsonResponse(['member' => $recipeTypes, 'totalItems' => count($recipeTypes)]);
    }
}
