<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\EnsureActiveShoppingList\EnsureActiveShoppingListCommand;
use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Repas\Application\Query\GetIngredient\GetIngredientQuery;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AddIngredientToActiveShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path:'/shopping-list/active/ingredient/{slug}/add', name: 'view_shopping_list_add_ingredient', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $slug): JsonResponse
    {
        $connectedUser = $this->getUser();
        assert($connectedUser instanceof AuthenticatedUser);

        // Liste active existante, ou création d'une nouvelle liste (active)
        $this->commandBus->dispatch(new EnsureActiveShoppingListCommand(UuidGenerator::new(), $connectedUser->getId()));

        //On Ajoute l'ingrédient dans la liste active
        $command = new AddIngredientToShoppingListCommand(
            $connectedUser->getId(),
            $slug
        );
        $this->commandBus->dispatch($command);

        $ingredient = $this->queryBus->ask(new GetIngredientQuery($slug, $connectedUser->getId(), $this->isGranted('ROLE_ADMIN')));
        $activeSummary = $this->queryBus->ask(new GetActiveShoppingListSummaryQuery($connectedUser->getId()));

        $html = $this->renderView('@Repas/Department/_ingredient_row.html.twig', [
            'ingredient' => $ingredient,
            'activeSummary' => $activeSummary,
        ]);

        return new JsonResponse([
            "views" => [
                [
                    "selector" => "#ingredient-row-{$ingredient->slug}",
                    "html" => $html,
                ]
            ]
        ]);
    }

}
