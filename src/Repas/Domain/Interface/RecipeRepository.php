<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;

interface RecipeRepository
{
    public function findOneById(string $id): Recipe;

    /**
     * @return Tab<Recipe>
     */
    public function findByAuthor(string $authorId): Tab;

    /**
     * @return Tab<Recipe>
     */
    public function findBy(array $criteria, ?array $orderBy = null): Tab;

    public function save(Recipe $recipe): void;

    /**
     * @return Tab<Recipe>
     */
    public function findByIngredient(Ingredient $ingredient): Tab;

    public function delete(Recipe $recipe): void;
}
