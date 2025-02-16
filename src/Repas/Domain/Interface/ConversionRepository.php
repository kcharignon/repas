<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\Tab;

interface ConversionRepository
{
    public function findById(string $id): ?Conversion;

    /**
     * @return Tab<Conversion>
     */
    public function findByIngredient(Ingredient $ingredient): Tab;

    public function findByIngredientAndStartUnitAndEndUnit(Ingredient $ingredient, Unit $startUnit, Unit $endUnit): ?Conversion;

    /**
     * @return Tab<Conversion>
     */
    public function findAll(): Tab;

    public function save(Conversion $conversion): void;
}
