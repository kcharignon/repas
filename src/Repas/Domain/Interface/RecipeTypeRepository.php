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

    /**
     * Un recipe type porte ce slug (le slug dérive du nom : deux créations du même nom se télescoperaient).
     */
    public function exists(string $slug): bool;

    public function save(RecipeType $recipeType): void;
}
