<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\Recipe;

interface RecipeRepository
{
    public function findOneById(string $id): Recipe;

    public function save(Recipe $recipe): void;
}
