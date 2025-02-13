<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

final class Conversion implements ModelInterface
{
    use ModelTrait;

    protected function __construct(
        private string      $id,
        private Unit        $startUnit,
        private Unit        $endUnit,
        private float       $coefficient,
        private ?Ingredient $ingredient,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStartUnit(): Unit
    {
        return $this->startUnit;
    }

    public function getEndUnit(): Unit
    {
        return $this->endUnit;
    }

    public function getCoefficient(): float
    {
        return $this->coefficient;
    }

    public function getIngredient(): ?Ingredient
    {
        return $this->ingredient;
    }

    public static function load(array $datas): self {
        return new self(
            id: $datas['id'],
            startUnit: $datas['start_unit'],
            endUnit: $datas['end_unit'],
            coefficient: $datas['coefficient'],
            ingredient: $datas['ingredient'],
        );
    }

    public static function create(
        string $id,
        Unit $startUnit,
        Unit $endUnit,
        float $coefficient,
        ?Ingredient $ingredient,
    ): self {
        return new self(
            $id,
            $startUnit,
            $endUnit,
            $coefficient,
            $ingredient,
        );
    }

    public function update(
        Unit $startUnit,
        Unit $endUnit,
        float $coefficient,
        ?Ingredient $ingredient = null,
    ): void {
        $this->startUnit = $startUnit;
        $this->endUnit = $endUnit;
        $this->coefficient = $coefficient;
        $this->ingredient = $ingredient;
    }
}
