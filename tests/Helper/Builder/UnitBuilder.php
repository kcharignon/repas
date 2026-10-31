<?php

namespace Repas\Tests\Helper\Builder;


use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Model\Dimension;
use Repas\Shared\Domain\Tool\StringTool;

class UnitBuilder implements Builder
{
    private ?string $slug;
    private ?string $name;
    private ?string $symbol;
    private ?Dimension $dimension = null;
    private ?float $baseFactor = null;

    private function initialize(): void
    {
        $this->name ??= "Pascal";
        $this->slug ??= "pascal";
        $this->symbol ??= "Pa";
    }

    public function withSlug(?string $slug): UnitBuilder
    {
        $this->slug = $slug;
        return $this;
    }

    public function withName(?string $name): UnitBuilder
    {
        $this->name = $name;
        $this->slug ??= StringTool::slugify($name);
        $this->symbol ??= substr($name, 0, 1);
        return $this;
    }

    /**
     * La dimension et le facteur de base vont ensemble ; `build()` ne les contrôle pas (`load()`), un test peut fabriquer un état incohérent.
     */
    public function withMeasure(?Dimension $dimension, ?float $baseFactor): UnitBuilder
    {
        $this->dimension = $dimension;
        $this->baseFactor = $baseFactor;
        return $this;
    }

    public function isGramme(): UnitBuilder
    {
        $this->name = 'gramme';
        $this->slug = 'gramme';
        $this->symbol = 'g';
        return $this->withMeasure(Dimension::WEIGHT, 1);
    }

    public function isKilo(): UnitBuilder
    {
        $this->name = 'kilo';
        $this->slug = 'kilo';
        $this->symbol = 'kg';
        return $this->withMeasure(Dimension::WEIGHT, 1000);
    }

    public function isUnite(): UnitBuilder
    {
        $this->name = 'unité';
        $this->slug = 'unite';
        $this->symbol = '';
        return $this->withMeasure(Dimension::COUNT, 1);
    }

    public function withSymbol(?string $symbol): UnitBuilder
    {
        $this->symbol = $symbol;
        return $this;
    }

    public function build(): Unit
    {
        $this->initialize();
        return Unit::load([
            'name' => $this->name,
            'slug' => $this->slug,
            'symbol' => $this->symbol,
            'dimension' => $this->dimension,
            'baseFactor' => $this->baseFactor,
        ]);
    }

    public function isBox(): self
    {
        $this->name = 'boîte';
        $this->slug = 'boite';
        $this->symbol = 'boîte';
        return $this->withMeasure(Dimension::PACKAGE, 1);
    }

    public function isCentiliter(): self
    {
        $this->name = 'centilitre';
        $this->slug = 'centilitre';
        $this->symbol = 'cl';
        return $this->withMeasure(Dimension::VOLUME, 10);
    }

    public function isMillilitre(): self
    {
        $this->name = 'millilitre';
        $this->slug = 'millilitre';
        $this->symbol = 'ml';
        return $this->withMeasure(Dimension::VOLUME, 1);
    }

    public function isLiter(): self
    {
        $this->name = 'litre';
        $this->slug = 'litre';
        $this->symbol = 'l';
        return $this->withMeasure(Dimension::VOLUME, 1000);
    }

    public function isBlock(): self
    {
        $this->name = 'plaquette';
        $this->slug = 'plaquette';
        $this->symbol = 'plaquette';
        return $this->withMeasure(Dimension::PACKAGE, 1);
    }

    public function isSoupSpoon(): self
    {
        $this->name = 'cuillère à soupe';
        $this->slug = 'cuillere-a-soupe';
        $this->symbol = 'cuillère à soupe';
        return $this;
    }

    /**
     * La tranche : une unité sans dimension (ni poids ni volume), comme la cuillère.
     */
    public function isSlice(): self
    {
        $this->name = 'tranche';
        $this->slug = 'tranche';
        $this->symbol = 'tranche';
        return $this;
    }

    public function isCoffeeSpoon(): self
    {
        $this->name = 'cuillère à café';
        $this->slug = 'cuillere-a-cafe';
        $this->symbol = 'cuillère à café';
        return $this;
    }

    public function isGlass(): self
    {
        $this->name = 'verre';
        $this->slug = 'verre';
        $this->symbol = 'verre';
        return $this;
    }

    public function isBowl(): self
    {
        $this->name = 'bol';
        $this->slug = 'bol';
        $this->symbol = 'bol';
        return $this;
    }

    public function isCoffeeCup(): self
    {
        $this->name = 'tasse à café';
        $this->slug = 'tasse-a-cafe';
        $this->symbol = 'tasse à café';
        return $this;
    }
}
