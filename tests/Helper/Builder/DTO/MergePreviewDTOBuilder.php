<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Repas\Application\Query\GetMergePreview\MergeIngredientDTO;
use Repas\Repas\Application\Query\GetMergePreview\MergePreviewDTO;
use Repas\Repas\Application\Query\GetMergePreview\MergeUnitDTO;
use Repas\Tests\Helper\Builder\Builder;

class MergePreviewDTOBuilder implements Builder
{
    private int $recipeCount = 7;
    private int $listCount = 2;
    private int $driveChoiceCount = 1;
    /** Le total APRÈS la fusion : par défaut ce qui se déplace plus ce que le gardé utilisait déjà (5 recettes, 1 liste) */
    private int $resultRecipeCount = 12;
    private int $resultListCount = 3;
    private int $recoveredConversionCount = 0;
    private bool $departmentChanges = false;
    private bool $listQuantitiesConverted = false;
    /** @var list<MergeUnitDTO> */
    private array $missingUnits = [];
    private ?string $blockedBy = null;

    public function withUsage(int $recipes, int $lists, int $driveChoices): self
    {
        $this->recipeCount = $recipes;
        $this->listCount = $lists;
        $this->driveChoiceCount = $driveChoices;
        return $this;
    }

    public function withResult(int $recipes, int $lists): self
    {
        $this->resultRecipeCount = $recipes;
        $this->resultListCount = $lists;
        return $this;
    }

    public function withRecoveredConversions(int $count): self
    {
        $this->recoveredConversionCount = $count;
        return $this;
    }

    public function withDepartmentChange(): self
    {
        $this->departmentChanges = true;
        return $this;
    }

    public function withListQuantitiesConverted(): self
    {
        $this->listQuantitiesConverted = true;
        return $this;
    }

    public function missing(string $slug, string $name): self
    {
        $this->missingUnits[] = new MergeUnitDTO($slug, $name);
        $this->blockedBy = 'UNIT_MISSING';
        return $this;
    }

    public function blockedBy(string $blockedBy): self
    {
        $this->blockedBy = $blockedBy;
        return $this;
    }

    public function build(): MergePreviewDTO
    {
        return new MergePreviewDTO(
            keep: new MergeIngredientDTO('tomate', 'Tomate', 'fruit-et-legume', 'Fruit & légume', 'kilo'),
            absorbed: new MergeIngredientDTO('tomates', 'Tomates', $this->departmentChanges ? 'surgele' : 'fruit-et-legume', $this->departmentChanges ? 'Surgelé' : 'Fruit & légume', 'kilo'),
            recipeCount: $this->recipeCount,
            listCount: $this->listCount,
            driveChoiceCount: $this->driveChoiceCount,
            departmentChanges: $this->departmentChanges,
            listQuantitiesConverted: $this->listQuantitiesConverted,
            missingUnits: $this->missingUnits,
            blockedBy: $this->blockedBy,
            canMerge: $this->blockedBy === null,
            resultRecipeCount: $this->resultRecipeCount,
            resultListCount: $this->resultListCount,
            recoveredConversionCount: $this->recoveredConversionCount,
        );
    }
}
