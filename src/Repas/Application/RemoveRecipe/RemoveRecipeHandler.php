<?php

namespace Repas\Repas\Application\RemoveRecipe;

use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RemoveRecipeHandler
{

    public function __construct(
        private RecipeRepository $recipeRepository,
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    /**
     * @throws RecipeException
     */
    public function __invoke(RemoveRecipeCommand $command): void
    {
        $recipe = $this->recipeRepository->findOneById($command->recipeId);

        $shoppingLists = $this->shoppingListRepository->findByRecipe($recipe);
        if (!$shoppingLists->empty()) {
            throw RecipeException::cannotRemoveExistInShoppingList();
        }

        $this->recipeRepository->delete($recipe);
    }
}
