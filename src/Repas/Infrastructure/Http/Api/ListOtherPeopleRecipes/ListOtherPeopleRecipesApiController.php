<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListOtherPeopleRecipes;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\ListOtherPeopleRecipesQuery;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipesDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListOtherPeopleRecipesApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/recipes/other-people', name: 'api_list_other_people_recipes', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        summary: "Recettes des autres utilisateurs que l'utilisateur authentifié n'a pas encore copiées : triées par catégorie, ou cherchées par nom du plus pertinent au moins pertinent",
        description: "Sans `q`, les recettes sont triées par catégorie (l'ordre des types de recette), puis par nom, puis par identifiant. Avec `q`, seules les recettes dont le nom correspond sont rendues, du plus pertinent au moins pertinent (Jaro-Winkler, ADR 0019), à pertinence égale par nom puis par identifiant, toutes catégories confondues ; chaque recette porte sa catégorie (`typeSlug`, `typeName`). 200 recettes au plus par réponse (`totalItems` donne le vrai nombre). Sans autre paramètre, la première page de 200 : `limit` et `offset` facultatifs lisent la suite.",
    )]
    #[OA\Tag(name: 'Recipes')]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Nombre de recettes de la page, de 1 à 200', schema: new OA\Schema(type: 'integer', default: 200, minimum: 1, maximum: 200))]
    #[OA\Parameter(name: 'offset', in: 'query', required: false, description: 'Nombre de recettes sautées, dans l\'ordre de la réponse', schema: new OA\Schema(type: 'integer', default: 0, minimum: 0, maximum: 1000000))]
    #[OA\Parameter(name: 'q', in: 'query', required: false, description: 'Texte cherché dans le nom des recettes, 100 caractères au plus ; ni la casse ni les accents ne comptent et une faute de frappe est tolérée. Absent ou vide : pas de recherche. Un texte sans lettre ni chiffre ne trouve rien (liste vide)', schema: new OA\Schema(type: 'string', maxLength: 100), example: 'carbonnara')]
    #[OA\Response(
        response: 200,
        description: 'Recettes des autres',
        content: new OA\JsonContent(ref: new Model(type: OtherPeopleRecipesDTO::class)),
    )]
    #[OA\Response(response: 422, description: 'Paramètre `limit`, `offset` ou `q` hors de ses bornes')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, #[MapQueryString(validationFailedStatusCode: 422)] ListOtherPeopleRecipesRequestQuery $page = new ListOtherPeopleRecipesRequestQuery()): JsonResponse
    {
        /** @var OtherPeopleRecipesDTO $recipes */
        $recipes = $this->queryBus->ask(new ListOtherPeopleRecipesQuery($user->getId(), $page->limit, $page->offset, $page->q));

        return new JsonResponse($recipes);
    }
}
