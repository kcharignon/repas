<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

final class Conversion implements ModelInterface
{
    use ModelTrait;

    protected function __construct(
        private string      $slug,
        private Unit        $startUnit,
        private Unit        $endUnit,
        private float       $coefficient,
        private ?Ingredient $ingredient,
    ) {
    }

    public function getSlug(): string
    {
        return $this->slug;
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

    public function getId(): string
    {
        return $this->slug;
    }

    public static function load(array $datas): self {
        return new self(
            slug: $datas['slug'],
            startUnit: $datas['start_unit'],
            endUnit: $datas['end_unit'],
            coefficient: $datas['coefficient'],
            ingredient: $datas['ingredient'],
        );
    }

    public static function create(
        Unit $startUnit,
        Unit $endUnit,
        float $coefficient,
        ?Ingredient $ingredient,
    ): self {
        $slug = self::generateSlug($ingredient, $startUnit, $endUnit);
        return new self(
            $slug,
            $startUnit,
            $endUnit,
            $coefficient,
            $ingredient,
        );
    }

    private static function generateSlug(?Ingredient $ingredient, Unit $startUnit, Unit $endUnit): string
    {
        return trim($ingredient?->getSlug() . '-' . $startUnit->getSlug() . '-' . $endUnit->getSlug(), '-');
    }
}
