<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Application\Query\GetActiveShoppingListSummary\GetActiveShoppingListSummaryQuery;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AddIngredientToActiveShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly ShoppingListRepository $shoppingListRepository,
        private readonly IngredientRepository $ingredientRepository,
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path:'/shopping-list/active/ingredient/{slug}/add', name: 'view_shopping_list_add_ingredient', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $slug): JsonResponse
    {
        $connectedUser = $this->getUser();
        assert($connectedUser instanceof User);

        // Recuperation de la liste active ou creation d'une nouvelle liste (active)
        $activeShoppingList = $this->shoppingListRepository->findOneActivateByOwner($connectedUser);
        if (!$activeShoppingList instanceof ShoppingList) {
            // Creation d'une nouvelle liste active
            $command = new CreateShoppingListCommand(
                UuidGenerator::new(),
                $connectedUser->getId(),
            );
            $this->commandBus->dispatch($command);
        }

        //On Ajoute l'ingrédient dans la liste active
        $command = new AddIngredientToShoppingListCommand(
            $connectedUser->getId(),
            $slug
        );
        $this->commandBus->dispatch($command);

        $ingredient = $this->ingredientRepository->findOneBySlug($slug);
        $activeSummary = $this->queryBus->ask(new GetActiveShoppingListSummaryQuery($connectedUser->getId()));

        $html = $this->renderView('@Repas/Department/_ingredient_row.html.twig', [
            'ingredient' => $ingredient,
            'activeSummary' => $activeSummary,
        ]);

        return new JsonResponse([
            "views" => [
                [
                    "selector" => "#ingredient-row-{$ingredient->getSlug()}",
                    "html" => $html,
                ]
            ]
        ]);
    }

}
