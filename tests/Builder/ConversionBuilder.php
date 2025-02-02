<?php

namespace Repas\Tests\Builder;


use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\UuidGenerator;

class ConversionBuilder implements Builder
{
    private ?string                           $id = null;
    private Unit|UnitBuilder|null             $startUnit = null;
    private Unit|UnitBuilder|null             $endUnit = null;
    private ?float                            $coefficient = null;
    private Ingredient|IngredientBuilder|null $ingredient = null;


    public function build(): Conversion
    {
        $this->initialize();
        return Conversion::load([
            'id' => $this->id,
            'start_unit' => $this->startUnit instanceof UnitBuilder ? $this->startUnit->build() : $this->startUnit,
            'end_unit' => $this->endUnit instanceof UnitBuilder ? $this->endUnit->build() : $this->endUnit,
            'coefficient' => $this->coefficient,
            'ingredient' => $this->ingredient instanceof IngredientBuilder ? $this->ingredient->build() : $this->ingredient,
        ]);
    }

    public function setStartUnit(Unit|UnitBuilder $startUnit): ConversionBuilder
    {
        $this->startUnit = $startUnit;
        return $this;
    }

    public function setEndUnit(Unit|UnitBuilder $endUnit): ConversionBuilder
    {
        $this->endUnit = $endUnit;
        return $this;
    }

    public function setCoefficient(float $coefficient): ConversionBuilder
    {
        $this->coefficient = $coefficient;
        return $this;
    }

    public function setIngredient(IngredientBuilder|Ingredient $ingredient): ConversionBuilder
    {
        $this->ingredient = $ingredient;
        return $this;
    }

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->startUnit ??= new UnitBuilder()->isBox();
        $this->endUnit ??= new UnitBuilder()->isPiece();
        $this->coefficient ??= 12;
        $this->ingredient ??= new IngredientBuilder()->isEgg();
    }

    public function isPieceToGrammeForEgg(): self
    {
        $this->startUnit = new UnitBuilder()->isPiece();
        $this->endUnit = new UnitBuilder()->isGramme();
        $this->coefficient = 60;
        $this->ingredient = new IngredientBuilder()->isEgg();
        return $this;
    }
}
