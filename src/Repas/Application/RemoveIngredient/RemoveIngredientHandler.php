<?php

namespace Repas\Repas\Application\RemoveIngredient;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RemoveIngredientHandler
{

    public function __construct(
        private IngredientRepository $ingredientRepository,
        private RecipeRepository $recipeRepository,
        private ShoppingListRepository $shoppingListRepository,
        private ConversionRepository $conversionRepository,
    ) {
    }

    /**
     * @throws IngredientException
     */
    public function __invoke(RemoveIngredientCommand $command): void
    {
        $ingredient = $this->ingredientRepository->findOneBySlug($command->ingredientId);

        // Si l'ingrédient est dans une recette, alors on ne le supprime pas
        $recipes = $this->recipeRepository->findByIngredient($ingredient);
        if (!$recipes->empty()) {
            throw IngredientException::cannotBeRemoveExistInRecipe($ingredient->getId());
        }

        // Si l'ingrédient est dans une liste de course, alors on ne le supprime pas
        $shoppingLists = $this->shoppingListRepository->findByIngredient($ingredient);
        if (!$shoppingLists->empty()) {
            throw IngredientException::cannotBeRemoveExistInShoppingList($ingredient->getId());
        }

        $this->conversionRepository->deleteByIngredient($ingredient);
        $this->ingredientRepository->delete($ingredient);
    }
}
