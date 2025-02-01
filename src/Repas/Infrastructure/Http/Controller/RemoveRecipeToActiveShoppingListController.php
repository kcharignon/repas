<?php

namespace Repas\Repas\Infrastructure\Http\Controller;


use Repas\Repas\Application\RemoveRecipeToActiveShoppingList\RemoveRecipeToActiveShoppingListCommand;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class RemoveRecipeToActiveShoppingListController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly ShoppingListRepository $shoppingListRepository,
        private readonly RecipeRepository $recipeRepository,
    ) {
    }

    #[Route(path: '/shopping-list/active/recipe/{id}/remove', name: 'view_shopping_list_remove_recipe', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        $connectedUser = $this->getUser();
        assert($connectedUser instanceof User);

        $command = new RemoveRecipeToActiveShoppingListCommand(
            $connectedUser->getId(),
            $id
        );
        $this->commandBus->dispatch($command);

        // Chargement des données pour générer une vue à jours
        $activeShoppingList = $this->shoppingListRepository->findOnePlanningByOwner($connectedUser);
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
