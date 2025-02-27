<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Model\User;

interface RecipeRepository
{
    public function findOneById(string $id): Recipe;

    /**
     * @return Tab<Recipe>
     */
    public function findByAuthor(User $author): Tab;

    /**
     * @return Tab<Recipe>
     */
    public function findByAuthorAndType(User $author, RecipeType $type): Tab;

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
