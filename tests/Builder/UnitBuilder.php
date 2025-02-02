<?php

namespace Repas\Tests\Builder;


use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\StringTool;

class UnitBuilder implements Builder
{
    private ?string $slug;
    private ?string $name;
    private ?string $symbol;

    private function initialize(): void
    {
        $this->name ??= "Pascal";
        $this->slug ??= "pascal";
        $this->symbol ??= "Pa";
    }

    public function setSlug(?string $slug): UnitBuilder
    {
        $this->slug = $slug;
        return $this;
    }

    public function setName(?string $name): UnitBuilder
    {
        $this->name = $name;
        $this->slug ??= StringTool::slugify($name);
        $this->symbol ??= substr($name, 0, 1);
        return $this;
    }

    public function isGramme(): UnitBuilder
    {
        $this->name = 'gramme';
        $this->slug = 'gramme';
        $this->symbol = 'g';
        return $this;
    }

    public function isKilo(): UnitBuilder
    {
        $this->name = 'kilo';
        $this->slug = 'kilo';
        $this->symbol = 'kg';
        return $this;
    }

    public function isPiece(): UnitBuilder
    {
        $this->name = 'piece';
        $this->slug = 'piece';
        $this->symbol = '';
        return $this;
    }

    public function setSymbol(?string $symbol): UnitBuilder
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
        ]);
    }

    public function isBox(): self
    {
        $this->name = 'boîte';
        $this->slug = 'boite';
        $this->symbol = 'boîte';
        return $this;
    }

    public function isCentilitre(): self
    {
        $this->name = 'centilitre';
        $this->slug = 'centilitre';
        $this->symbol = 'cl';
        return $this;
    }

    public function isMillilitre(): self
    {
        $this->name = 'millilitre';
        $this->slug = 'millilitre';
        $this->symbol = 'ml';
        return $this;
    }

    public function isLiter(): self
    {
        $this->name = 'litre';
        $this->slug = 'litre';
        $this->symbol = 'l';
        return $this;
    }
}
