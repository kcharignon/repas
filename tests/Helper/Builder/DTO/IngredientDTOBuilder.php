<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Repas\Application\Query\GetIngredient\IngredientDTO;
use Repas\Tests\Helper\Builder\Builder;

class IngredientDTOBuilder implements Builder
{
    private string $slug = 'farine';
    private string $name = 'farine';
    private string $departmentSlug = 'cereale';
    private string $departmentName = 'céréale';
    private string $cookingUnitSlug = 'gramme';
    private string $cookingUnitName = 'gramme';
    private string $purchaseUnitSlug = 'gramme';
    private string $purchaseUnitName = 'gramme';
    private string $purchaseUnitSymbol = 'g';
    private ?float $coefficient = null;
    private ?string $creatorId = null;
    private bool $canEdit = false;
    private int $recipeCount = 0;
    private int $listCount = 0;

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

    public function withDepartment(string $slug, string $name): self
    {
        $this->departmentSlug = $slug;
        $this->departmentName = $name;
        return $this;
    }

    public function withCookingUnit(string $slug, string $name): self
    {
        $this->cookingUnitSlug = $slug;
        $this->cookingUnitName = $name;
        return $this;
    }

    public function withPurchaseUnit(string $slug, string $name, string $symbol): self
    {
        $this->purchaseUnitSlug = $slug;
        $this->purchaseUnitName = $name;
        $this->purchaseUnitSymbol = $symbol;
        return $this;
    }

    public function withCoefficient(?float $coefficient): self
    {
        $this->coefficient = $coefficient;
        return $this;
    }

    public function withCreatorId(?string $creatorId): self
    {
        $this->creatorId = $creatorId;
        return $this;
    }

    public function editable(): self
    {
        $this->canEdit = true;
        return $this;
    }

    public function withUsage(int $recipeCount, int $listCount): self
    {
        $this->recipeCount = $recipeCount;
        $this->listCount = $listCount;
        return $this;
    }

    public function build(): IngredientDTO
    {
        return new IngredientDTO(
            slug: $this->slug,
            name: $this->name,
            image: '',
            departmentSlug: $this->departmentSlug,
            departmentName: $this->departmentName,
            cookingUnitSlug: $this->cookingUnitSlug,
            cookingUnitName: $this->cookingUnitName,
            purchaseUnitSlug: $this->purchaseUnitSlug,
            purchaseUnitName: $this->purchaseUnitName,
            purchaseUnitSymbol: $this->purchaseUnitSymbol,
            hasSameUnit: $this->cookingUnitSlug === $this->purchaseUnitSlug,
            coefficient: $this->coefficient,
            creatorId: $this->creatorId,
            canEdit: $this->canEdit,
            recipeCount: $this->recipeCount,
            listCount: $this->listCount,
        );
    }
}
