<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListOtherPeopleRecipes;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\ListOtherPeopleRecipesQuery;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipeDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    #[OA\Get(summary: "Recettes des autres utilisateurs que l'utilisateur authentifié n'a pas encore copiées, triées par slug")]
    #[OA\Tag(name: 'Recipes')]
    #[OA\Response(
        response: 200,
        description: 'Recettes des autres',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: OtherPeopleRecipeDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 12),
        ]),
    )]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var list<OtherPeopleRecipeDTO> $recipes */
        $recipes = $this->queryBus->ask(new ListOtherPeopleRecipesQuery($user->getId()));

        return new JsonResponse(['member' => $recipes, 'totalItems' => count($recipes)]);
    }
}
