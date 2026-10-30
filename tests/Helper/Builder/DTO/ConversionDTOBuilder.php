<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Repas\Tests\Helper\Builder\Builder;

class ConversionDTOBuilder implements Builder
{
    private string $id = 'conversion-1';
    private ?string $ingredientSlug = 'beurre';
    private ?string $ingredientName = 'beurre';
    private ?string $creatorEmail = null;
    private string $startUnitSlug = 'plaquette';
    private string $startUnitName = 'plaquette';
    private string $endUnitSlug = 'gramme';
    private string $endUnitName = 'gramme';
    private float $coefficient = 250;
    private ?string $departmentSlug = 'epicerie';
    private ?string $departmentName = 'épicerie';

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function withIngredient(?string $slug, ?string $name, ?string $creatorEmail = null): self
    {
        $this->ingredientSlug = $slug;
        $this->ingredientName = $name;
        $this->creatorEmail = $creatorEmail;
        return $this;
    }

    public function withDepartment(?string $slug, ?string $name): self
    {
        $this->departmentSlug = $slug;
        $this->departmentName = $name;
        return $this;
    }

    public function withUnits(string $startSlug, string $startName, string $endSlug, string $endName): self
    {
        $this->startUnitSlug = $startSlug;
        $this->startUnitName = $startName;
        $this->endUnitSlug = $endSlug;
        $this->endUnitName = $endName;
        return $this;
    }

    public function withCoefficient(float $coefficient): self
    {
        $this->coefficient = $coefficient;
        return $this;
    }

    public function build(): ConversionDTO
    {
        return new ConversionDTO(
            $this->id, $this->ingredientSlug, $this->ingredientName, $this->creatorEmail,
            $this->startUnitSlug, $this->startUnitName, $this->endUnitSlug, $this->endUnitName, $this->coefficient,
            // une conversion générale n'a pas d'ingrédient, donc pas de rayon
            $this->ingredientSlug === null ? null : $this->departmentSlug, $this->ingredientSlug === null ? null : $this->departmentName,
        );
    }
}
