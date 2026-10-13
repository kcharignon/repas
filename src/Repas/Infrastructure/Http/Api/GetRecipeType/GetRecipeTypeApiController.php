<?php

namespace Repas\Repas\Infrastructure\Http\Api\GetRecipeType;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\GetRecipeType\GetRecipeTypeQuery;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetRecipeTypeApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/recipe-types/{slug}', name: 'api_get_recipe_type', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: 'Détail d\'un type de recette')]
    #[OA\Tag(name: 'Recipe types')]
    #[OA\Response(
        response: 200,
        description: 'Un type de recette',
        content: new OA\JsonContent(ref: new Model(type: RecipeTypeDTO::class)),
    )]
    #[OA\Response(response: 404, description: 'Inconnu')]
    public function __invoke(string $slug): JsonResponse
    {
        /** @var RecipeTypeDTO $item */
        $item = $this->queryBus->ask(new GetRecipeTypeQuery($slug));

        return new JsonResponse($item);
    }
}
