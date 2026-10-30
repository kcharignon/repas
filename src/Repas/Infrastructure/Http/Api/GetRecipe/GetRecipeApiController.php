<?php

namespace Repas\Repas\Infrastructure\Http\Api\GetRecipe;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\GetRecipe\GetRecipeQuery;
use Repas\Repas\Application\Query\GetRecipe\RecipeDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetRecipeApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    // L'identifiant est un UUID : la contrainte évite de capter `/recipes/other-people`
    #[Route(path: '/api/v1/recipes/{id}', name: 'api_get_recipe', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: 'Détail d\'une recette et de ses ingrédients (lisible par tout utilisateur authentifié, sauf une recette secrète d\'un autre)')]
    #[OA\Tag(name: 'Recipes')]
    #[OA\Response(
        response: 200,
        description: 'Détail de la recette',
        headers: [new OA\Header(header: 'ETag', description: 'Version de la recette entre guillemets : à renvoyer dans `If-Match` pour la modifier (ADR 0016)', schema: new OA\Schema(type: 'string', example: '"3"'))],
        content: new OA\JsonContent(ref: new Model(type: RecipeDTO::class)),
    )]
    #[OA\Response(response: 404, description: "Recette inconnue, ou secrète et d'un autre : seul son auteur la lit, un administrateur n'y échappe pas (jamais `403`, on ne révèle pas qu'elle existe)")]
    public function __invoke(string $id, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var RecipeDTO $recipe */
        $recipe = $this->queryBus->ask(new GetRecipeQuery($id, $user->getId()));

        return new JsonResponse($recipe, headers: ['ETag' => EntityVersion::etag($recipe->version)]);
    }
}
