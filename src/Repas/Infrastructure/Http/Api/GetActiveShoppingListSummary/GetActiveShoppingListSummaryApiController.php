<?php

namespace Repas\Repas\Infrastructure\Http\Api\GetActiveShoppingListSummary;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\GetActiveShoppingListSummary\ActiveShoppingListSummaryDTO;
use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetActiveShoppingListSummaryApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/shopping-lists/active/summary', name: 'api_get_active_shopping_list_summary', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Contenu de la liste active de l'utilisateur authentifié : quantité par ingrédient et recettes planifiées")]
    #[OA\Tag(name: 'Shopping lists')]
    #[OA\Response(
        response: 200,
        description: "Résumé de la liste active ; sans liste active, `shoppingListId` est null et les deux collections sont vides",
        content: new OA\JsonContent(ref: new Model(type: ActiveShoppingListSummaryDTO::class)),
    )]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var ActiveShoppingListSummaryDTO $summary */
        $summary = $this->queryBus->ask(new GetActiveShoppingListSummaryQuery($user->getId()));

        // `(object)` : une table de quantités vide doit rester `{}` en JSON, pas devenir `[]`
        return new JsonResponse([
            'shoppingListId' => $summary->shoppingListId,
            'quantityByIngredientSlug' => (object) $summary->quantityByIngredientSlug,
            'plannedRecipeIds' => $summary->plannedRecipeIds,
        ]);
    }
}
