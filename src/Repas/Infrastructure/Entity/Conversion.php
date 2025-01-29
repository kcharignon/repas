<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Conversion as ConversionModel;

#[ORM\Entity]
#[ORM\Table(name: 'conversion')]
class Conversion
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 767, unique: true)]
    private ?string $slug = null;

    #[ORM\JoinColumn(name: 'start_unit', nullable: false)]
    private ?string $startUnitSlug = null;

    #[ORM\JoinColumn(name: 'end_unit', nullable: false)]
    private ?string $endUnitSlug = null;

    #[ORM\Column]
    private ?float $coefficient = null;

    #[ORM\Column(name: 'ingredient', nullable: true)]
    private ?string $ingredientSlug = null;

    public function __construct(
        ?string $id,
        ?string $startUnitSlug,
        ?string $endUnitSlug,
        ?float $coefficient,
        ?string $ingredientSlug,
    ) {
        $this->slug = $id;
        $this->startUnitSlug = $startUnitSlug;
        $this->endUnitSlug = $endUnitSlug;
        $this->coefficient = $coefficient;
        $this->ingredientSlug = $ingredientSlug;
    }


    public static function fromModel(ConversionModel $conversionModel): static
    {
        return new static(
            id: $conversionModel->getId(),
            startUnitSlug: $conversionModel->getStartUnit()->getSlug(),
            endUnitSlug: $conversionModel->getEndUnit()->getSlug(),
            coefficient: $conversionModel->getCoefficient(),
            ingredientSlug: $conversionModel->getIngredient()->getSlug(),
        );
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function getStartUnitSlug(): ?string
    {
        return $this->startUnitSlug;
    }

    public function setStartUnitSlug(?string $startUnitSlug): static
    {
        $this->startUnitSlug = $startUnitSlug;

        return $this;
    }

    public function getEndUnitSlug(): ?string
    {
        return $this->endUnitSlug;
    }

    public function setEndUnitSlug(?string $endUnitSlug): static
    {
        $this->endUnitSlug = $endUnitSlug;

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

    public function getIngredientSlug(): ?string
    {
        return $this->ingredientSlug;
    }

    public function setIngredientSlug(?string $ingredientSlug): static
    {
        $this->ingredientSlug = $ingredientSlug;

        return $this;
    }
}
