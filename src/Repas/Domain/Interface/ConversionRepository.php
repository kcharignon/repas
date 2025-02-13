<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Domain\Tool\Tab;

interface ConversionRepository
{
    /**
     * @return Tab<Conversion>
     */
    public function findByIngredient(Ingredient $ingredient): Tab;

    /**
     * @return Tab<Conversion>
     */
    public function findAll(): Tab;

    public function save(Conversion $conversion): void;
}
