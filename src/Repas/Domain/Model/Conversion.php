<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

class Conversion implements ModelInterface
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

    public static function load(array $datas): static {
        return new static(
            $datas['slug'],
            Unit::load($datas['start_unit']),
            Unit::load($datas['end_unit']),
            $datas['coefficient'],
            $datas['ingredient'] ? Unit::load($datas['ingredient']) : null,
        );
    }

    public static function create(
        Unit $startUnit,
        Unit $endUnit,
        float $coefficient,
        ?Ingredient $ingredient,
    ): static {
        $slug = self::generateSlug($ingredient, $startUnit, $endUnit);
        return new static(
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
