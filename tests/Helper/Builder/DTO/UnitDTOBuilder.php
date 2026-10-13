<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Repas\Application\Query\ListUnits\UnitDTO;
use Repas\Tests\Helper\Builder\Builder;

class UnitDTOBuilder implements Builder
{
    private string $slug;
    private string $name;
    private string $symbol;

    private function initialize(): void
    {
        $this->slug ??= 'gramme';
        $this->name ??= 'gramme';
        $this->symbol ??= 'g';
    }

    public function withSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function withName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function withSymbol(string $symbol): self
    {
        $this->symbol = $symbol;
        return $this;
    }

    public function build(): UnitDTO
    {
        $this->initialize();
        return new UnitDTO($this->slug, $this->name, $this->symbol);
    }
}
