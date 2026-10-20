<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListAvailableIngredients;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListAvailableIngredients\IngredientOptionDTO;
use Repas\Repas\Application\Query\ListAvailableIngredients\ListAvailableIngredientsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListAvailableIngredientsApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients', name: 'api_list_available_ingredients', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Ingrédients que l'utilisateur authentifié peut utiliser, triés par nom : les siens et les communs qu'il n'a pas adoptés (un commun adopté est remplacé par sa copie, qui est à lui) ; choix des recettes")]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\Parameter(name: 'q', in: 'query', required: false, description: 'Ne garde que les ingrédients dont le nom contient ce texte (sans tenir compte de la casse ni des accents), 100 caractères au plus', schema: new OA\Schema(type: 'string', maxLength: 100), example: 'cre')]
    #[OA\Response(
        response: 200,
        description: 'Ingrédients utilisables',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: IngredientOptionDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 42),
        ]),
    )]
    #[OA\Response(response: 422, description: 'Paramètre `q` invalide (trop long)')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, #[MapQueryString(validationFailedStatusCode: 422)] ListAvailableIngredientsRequestQuery $filter = new ListAvailableIngredientsRequestQuery()): JsonResponse
    {
        /** @var list<IngredientOptionDTO> $ingredients */
        $ingredients = $this->queryBus->ask(new ListAvailableIngredientsQuery($user->getId(), $filter->q));

        return new JsonResponse(['member' => $ingredients, 'totalItems' => count($ingredients)]);
    }
}
