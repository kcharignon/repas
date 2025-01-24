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
            slug: $datas['slug'],
            startUnit: static::loadModel($datas['start_unit'], Unit::class),
            endUnit: static::loadModel($datas['end_unit'], Unit::class),
            coefficient: $datas['coefficient'],
            ingredient: static::loadModel($datas['ingredient'], Ingredient::class),
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
