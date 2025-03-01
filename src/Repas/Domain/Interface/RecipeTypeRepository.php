<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Shared\Domain\Tool\Tab;

interface RecipeTypeRepository
{
    /**
     * @return Tab<RecipeType>
     */
    public function findAll(): Tab;

    /**
     * @throws RecipeException
     */
    public function findOneBySlug(string $slug): RecipeType;

    public function save(RecipeType $recipeType): void;
}
