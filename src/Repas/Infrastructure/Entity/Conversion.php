<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Conversion as ConversionModel;
use Repas\Repository\ConversionRepository;

#[ORM\Entity(repositoryClass: ConversionRepository::class)]
class Conversion
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 767, unique: true)]
    private ?string $slug = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'start_unit', referencedColumnName: 'slug', nullable: false)]
    private ?Unit $startUnit = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'end_unit', referencedColumnName: 'slug', nullable: false)]
    private ?Unit $endUnit = null;

    #[ORM\Column]
    private ?float $coefficient = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'ingredient', referencedColumnName: 'slug', nullable: true)]
    private ?Ingredient $ingredient = null;

    public function __construct(
        ?string $id,
        ?Unit $startUnit,
        ?Unit $endUnit,
        ?float $coefficient,
        ?Ingredient $ingredient
    ) {
        $this->slug = $id;
        $this->startUnit = $startUnit;
        $this->endUnit = $endUnit;
        $this->coefficient = $coefficient;
        $this->ingredient = $ingredient;
    }


    public static function fromModel(ConversionModel $conversionModel): static
    {
        return static::fromData($conversionModel->toArray());
    }

    public static function fromData(array $data): static
    {
        return new static(
            $data['slug'],
            Unit::fromData($data['start_unit']),
            Unit::fromData($data['end_unit']),
            $data['coefficient'],
            $data['ingredient'] ? Ingredient::fromData($data['ingredient']) : null,
        );
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function getStartUnit(): ?Unit
    {
        return $this->startUnit;
    }

    public function setStartUnit(?Unit $startUnit): static
    {
        $this->startUnit = $startUnit;

        return $this;
    }

    public function getEndUnit(): ?Unit
    {
        return $this->endUnit;
    }

    public function setEndUnit(?Unit $endUnit): static
    {
        $this->endUnit = $endUnit;

        return $this;
    }

    public function getCoefficient(): ?float
    {
        return $this->coefficient;
    }

    public function setCoefficient(float $coefficient): static
    {
        $this->coefficient = $coefficient;

        return $this;
    }

    public function getIngredient(): ?Ingredient
    {
        return $this->ingredient;
    }

    public function setIngredient(?Ingredient $ingredient): static
    {
        $this->ingredient = $ingredient;

        return $this;
    }
}
