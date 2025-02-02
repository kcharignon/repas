<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AddRecipeToActiveShoppingListViewController extends AbstractController
{
    public function __construct(
        private readonly ShoppingListRepository $shoppingListRepository,
        private readonly RecipeRepository $recipeRepository,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/shopping-list/active/recipe/{id}/add', name: 'view_shopping_list_add_recipe', methods: ['POST'])]
    public function __invoke(string $id): Response
    {
        $connectedUser = $this->getUser();
        assert($connectedUser instanceof User);

        // Recuperation de la liste active ou creation d'une nouvelle liste (active)
        $activeShoppingList = $this->shoppingListRepository->findOnePlanningByOwner($connectedUser);
        if (!$activeShoppingList instanceof ShoppingList) {
            // Creation d'une nouvelle liste active
            $activeShoppingListId = UuidGenerator::new();
            $command = new CreateShoppingListCommand(
                $activeShoppingListId,
                $connectedUser->getId(),
            );
            $this->commandBus->dispatch($command);
        } else {
            $activeShoppingListId = $activeShoppingList->getId();
        }

        // Ajout de la recette dans la liste active
        $command = new PlannedMealCommand(
            $connectedUser->getId(),
            $id
        );
        $this->commandBus->dispatch($command);

        // Chargement des donnees pour générer une vue avec des données à jours
        $activeShoppingList = $this->shoppingListRepository->findOneById($activeShoppingListId);
        $recipe = $this->recipeRepository->findOneById($id);
        return new JsonResponse([
            "views" => [
                [
                    "selector" => "#recipe_".$id,
                    "html" => $this->renderView("@Repas/Recipe/_recipe_row.html.twig", [
                        "shoppingList" => $activeShoppingList,
                        "recipe" => $recipe,
                    ])
                ]
            ]
        ]);
    }

}
