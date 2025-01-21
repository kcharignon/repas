<?php

namespace Repas\Repas\Domain\Model;


class Conversion
{
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

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'start_unit' => $this->startUnit->toArray(),
            'end_unit' => $this->endUnit->toArray(),
            'coefficient' => $this->coefficient,
            'ingredient' => $this->ingredient?->toArray(),
        ];
    }
}
