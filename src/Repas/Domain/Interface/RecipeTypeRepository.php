<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\RecipeType;
use Repas\Shared\Domain\Tool\Tab;

interface RecipeTypeRepository
{
    /**
     * @return Tab<RecipeType>
     */
    public function getAll(): Tab;

    public function getOneBySlug(string $slug): RecipeType;
}
