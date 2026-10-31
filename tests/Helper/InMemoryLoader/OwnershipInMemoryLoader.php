<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Application\Loader\OwnershipLoader;

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
     * @param array<string, string> $shoppingListRowOwners
     * @param list<string> $secretRecipes les identifiants des recettes secrètes (ADR 0033) : introuvables pour tout autre que leur auteur
     * @param array<string, string> $listTypeOwners
     */
    public function __construct(
        private readonly array $ingredientCreators = [],
        private readonly array $shoppingListOwners = [],
        private readonly array $recipeAuthors = [],
        private readonly array $mealOwners = [],
        private readonly array $shoppingListRowOwners = [],
        private readonly array $secretRecipes = [],
        private readonly array $listTypeOwners = [],
    ) {
    }

    public function listTypeOwnerId(string $listTypeId): string
    {
        return $this->listTypeOwners[$listTypeId] ?? throw ShoppingListTypeException::notFound();
    }

    public function ingredientCreatorId(string $slug): ?string
    {
        return array_key_exists($slug, $this->ingredientCreators) ? $this->ingredientCreators[$slug] : throw IngredientException::notFound($slug);
    }

    public function shoppingListOwnerId(string $shoppingListId): string
    {
        return $this->shoppingListOwners[$shoppingListId] ?? throw ShoppingListException::shoppingListNotFound($shoppingListId);
    }

    public function recipeAuthorIdFor(string $recipeId, string $userId): string
    {
        $authorId = $this->recipeAuthors[$recipeId] ?? throw RecipeException::notFound($recipeId);

        return in_array($recipeId, $this->secretRecipes, true) && $authorId !== $userId ? throw RecipeException::notFound($recipeId) : $authorId;
    }

    public function mealOwnerId(string $mealId): string
    {
        return $this->mealOwners[$mealId] ?? throw MealException::notFound();
    }

    public function shoppingListRowOwnerId(string $shoppingListRowId): string
    {
        return $this->shoppingListRowOwners[$shoppingListRowId] ?? throw ShoppingListRowException::notFound($shoppingListRowId);
    }
}
