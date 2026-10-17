<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Unit as UnitModel;
use Repas\Shared\Domain\Model\Dimension;

#[ORM\Entity]
#[ORM\Table(name: 'unit')]
class Unit
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $symbol = null;

    #[ORM\Column(type: 'string', nullable: true, enumType: Dimension::class)]
    private ?Dimension $dimension = null;

    #[ORM\Column(nullable: true)]
    private ?float $baseFactor = null;

    public function __construct(
        string $slug,
        string $name,
        string $symbol,
        ?Dimension $dimension = null,
        ?float $baseFactor = null,
    ) {
        $this->slug = $slug;
        $this->name = $name;
        $this->symbol = $symbol;
        $this->dimension = $dimension;
        $this->baseFactor = $baseFactor;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSymbol(): ?string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): static
    {
        $this->symbol = $symbol;

        return $this;
    }

    public function getDimension(): ?Dimension
    {
        return $this->dimension;
    }

    public function setDimension(?Dimension $dimension): static
    {
        $this->dimension = $dimension;

        return $this;
    }

    public function getBaseFactor(): ?float
    {
        return $this->baseFactor;
    }

    public function setBaseFactor(?float $baseFactor): static
    {
        $this->baseFactor = $baseFactor;

        return $this;
    }

    public static function fromModel(UnitModel $unit): static
    {
        return new static(
            $unit->getSlug(),
            $unit->getName(),
            $unit->getSymbol(),
            $unit->getDimension(),
            $unit->getBaseFactor(),
        );
    }
}
