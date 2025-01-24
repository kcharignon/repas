<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\RecipeType;

interface RecipeTypeRepository
{
    /**
     * @return array<RecipeType>
     */
    public function getAll(): array;
}
