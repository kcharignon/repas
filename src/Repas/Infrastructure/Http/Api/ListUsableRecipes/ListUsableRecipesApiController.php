<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListUsableRecipes;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListUsableRecipes\ListUsableRecipesQuery;
use Repas\Repas\Application\Query\ListUsableRecipes\UsableRecipesDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListUsableRecipesApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    // `usable` n'est pas un identifiant de recette (36 caractères) : la lecture d'une recette (`/recipes/{id}`) ne le capte pas
    #[Route(path: '/api/v1/recipes/usable', name: 'api_list_usable_recipes', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        summary: "Les recettes qu'un éditeur peut proposer comme recette utilisée par une recette (celles de son auteur, sans composants, ordinaires si la recette l'est)",
        description: "Pour l'éditeur de recette (ADR 0040). Les recettes de l'auteur de `forRecipeId` (de l'utilisateur sans lui) qui n'ont PAS de composants (profondeur 1) et ne sont pas la recette elle-même. Ordinaires par défaut ; avec `secret=true` (la recette écrite est secrète, ou va le devenir) les recettes secrètes de l'utilisateur sont proposées aussi, à son auteur seul : jamais une recette secrète d'un autre, administrateur compris. Triées par nom puis par identifiant, ou par pertinence avec `q`. 200 recettes au plus (`totalItems` donne le vrai nombre). `canHaveComponents` est faux quand `forRecipeId` est lui-même utilisé par une autre recette (ou n'a pas de propriétaire) : le front masque alors la section.",
    )]
    #[OA\Tag(name: 'Recipes')]
    #[OA\Parameter(name: 'forRecipeId', in: 'query', required: false, description: "Identifiant de la recette qu'on modifie (36 caractères au plus) : les recettes proposées sont celles de son auteur, jamais elle-même. Il faut avoir le droit de la modifier (`403` sinon, `404` si elle est inconnue ou secrète d'un autre). Absent : la recette qu'on crée, donc les recettes de l'utilisateur", schema: new OA\Schema(type: 'string', maxLength: 36))]
    #[OA\Parameter(name: 'secret', in: 'query', required: false, description: "La recette écrite est (ou va devenir) secrète : ses recettes secrètes sont alors proposées aussi, à son auteur seul. Faux par défaut", schema: new OA\Schema(type: 'boolean', default: false))]
    #[OA\Parameter(name: 'q', in: 'query', required: false, description: 'Texte cherché dans le nom des recettes, 100 caractères au plus ; ni la casse ni les accents ne comptent et une faute de frappe est tolérée. Absent ou vide : pas de recherche. Un texte sans lettre ni chiffre ne trouve rien (liste vide)', schema: new OA\Schema(type: 'string', maxLength: 100), example: 'pate sablee')]
    #[OA\Response(
        response: 200,
        description: 'Recettes proposables',
        content: new OA\JsonContent(ref: new Model(type: UsableRecipesDTO::class)),
    )]
    #[OA\Response(response: 403, description: "`forRecipeId` est la recette ordinaire d'un autre utilisateur (ou une recette sans propriétaire) et l'utilisateur n'est pas administrateur")]
    #[OA\Response(response: 404, description: "`forRecipeId` est inconnu, ou la recette secrète d'un autre (administrateur compris)")]
    #[OA\Response(response: 422, description: 'Paramètre `forRecipeId`, `secret` ou `q` hors de ses bornes')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, #[MapQueryString(validationFailedStatusCode: 422)] ListUsableRecipesRequestQuery $request = new ListUsableRecipesRequestQuery()): JsonResponse
    {
        // Le droit sur la recette qu'on modifie : comme `PUT /recipes/{id}` (`RECIPE_OWNER`, sans passe-droit pour la secrète d'un autre)
        if ($request->forRecipeId !== null && trim($request->forRecipeId) !== '') {
            $this->denyAccessUnlessGranted('RECIPE_OWNER', $request->forRecipeId);
        }

        /** @var UsableRecipesDTO $recipes */
        $recipes = $this->queryBus->ask(new ListUsableRecipesQuery($user->getId(), $request->forRecipeId, $request->secret, $request->q));

        return new JsonResponse($recipes);
    }
}
