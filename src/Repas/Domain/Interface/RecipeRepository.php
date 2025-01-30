<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Model\User;

interface RecipeRepository
{
    public function findOneById(string $id): Recipe;

    /**
     * @return Tab<Recipe>
     */
    public function findByAuthor(User $author): Tab;

    public function save(Recipe $recipe): void;
}
