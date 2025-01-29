<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\RecipeType;
use Repas\Shared\Domain\Tool\Tab;

interface RecipeTypeRepository
{
    /**
     * @return Tab<RecipeType>
     */
    public function findAll(): Tab;

    public function findOneBySlug(string $slug): RecipeType;
}
