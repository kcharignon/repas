<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListIngredientConversions;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListIngredientConversions\IngredientConversionsDTO;
use Repas\Repas\Application\Query\ListIngredientConversions\ListIngredientConversionsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListIngredientConversionsApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/ingredients/{slug}/conversions', name: 'api_list_ingredient_conversions', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(
        summary: "Conversions d'un ingrédient de l'utilisateur : les siennes (`member`, qu'il écrit) et les universelles qui touchent ses unités (`common`, lecture seule)",
        description: "L'ingrédient d'un autre utilisateur, un administrateur compris, est inconnu (404). `convertible` dit si l'unité des recettes de l'ingrédient se convertit en son unité d'achat.",
    )]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\Response(
        response: 200,
        description: "Conversions de l'ingrédient",
        content: new OA\JsonContent(ref: new Model(type: IngredientConversionsDTO::class)),
    )]
    #[OA\Response(response: 404, description: "Ingrédient inconnu, ou à un autre utilisateur (un administrateur n'a pas de passe-droit)")]
    public function __invoke(string $slug, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var IngredientConversionsDTO $conversions */
        $conversions = $this->queryBus->ask(new ListIngredientConversionsQuery($slug, $user->getId()));

        return new JsonResponse($conversions);
    }
}
