<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListOtherPeopleRecipeTypes;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes\ListOtherPeopleRecipeTypesQuery;
use Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes\OtherPeopleRecipeTypesDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListOtherPeopleRecipeTypesApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/recipes/other-people/types', name: 'api_list_other_people_recipe_types', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        summary: "Les catégories de « Recettes des autres », avec le nombre de recettes que l'utilisateur authentifié peut copier dans chacune",
        description: "Les mêmes règles que `GET /recipes/other-people` (pas les siennes, pas les secrètes d'un autre, pas les copies, pas celles qu'il a déjà copiées ; un administrateur voit toujours les recettes sans propriétaire) : `count` est exactement le `totalItems` de `GET /recipes/other-people?typeSlug=…`. Seules les catégories qui ont au moins une recette sont rendues, dans l'ordre des types de recette. Une seule requête SQL, quel que soit le nombre de catégories ou de recettes.",
    )]
    #[OA\Tag(name: 'Recipes')]
    #[OA\Response(
        response: 200,
        description: 'Catégories des recettes des autres',
        content: new OA\JsonContent(ref: new Model(type: OtherPeopleRecipeTypesDTO::class)),
    )]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var OtherPeopleRecipeTypesDTO $types */
        $types = $this->queryBus->ask(new ListOtherPeopleRecipeTypesQuery($user->getId(), $this->isGranted('ROLE_ADMIN')));

        return new JsonResponse($types);
    }
}
