<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Shared\Application\Interface\OwnershipLoader;

/**
 * Double idiot : propriétaires programmés par ressource, introuvable sinon.
 */
class OwnershipInMemoryLoader implements OwnershipLoader
{
    /**
     * @param array<string, ?string> $ingredientCreators slug => id du créateur (null : ingrédient commun)
     * @param array<string, string> $shoppingListOwners
     * @param array<string, string> $recipeAuthors
     * @param array<string, string> $mealOwners
     */
    public function __construct(
        private readonly array $ingredientCreators = [],
        private readonly array $shoppingListOwners = [],
        private readonly array $recipeAuthors = [],
        private readonly array $mealOwners = [],
    ) {
    }

    public function ingredientCreatorId(string $slug): ?string
    {
        return array_key_exists($slug, $this->ingredientCreators) ? $this->ingredientCreators[$slug] : throw IngredientException::notFound($slug);
    }

    public function shoppingListOwnerId(string $shoppingListId): string
    {
        return $this->shoppingListOwners[$shoppingListId] ?? throw ShoppingListException::shoppingListNotFound($shoppingListId);
    }

    public function recipeAuthorId(string $recipeId): string
    {
        return $this->recipeAuthors[$recipeId] ?? throw RecipeException::notFound($recipeId);
    }

    public function mealOwnerId(string $mealId): string
    {
        return $this->mealOwners[$mealId] ?? throw MealException::notFound();
    }
}
