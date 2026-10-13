<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Repas\Application\Query\GetRecipe\RecipeDTO;
use Repas\Repas\Application\Query\GetRecipe\RecipeRowDTO;
use Repas\Tests\Helper\Builder\Builder;

class RecipeDTOBuilder implements Builder
{
    private string $id = 'recipe-1';
    private string $name = 'pâtes carbonara';
    private int $serving = 4;
    private string $typeSlug = 'plat';
    private string $typeName = 'plat';
    /** @var list<RecipeRowDTO> */
    private array $rows = [];

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function withName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function withServing(int $serving): self
    {
        $this->serving = $serving;
        return $this;
    }

    public function withType(string $slug, string $name): self
    {
        $this->typeSlug = $slug;
        $this->typeName = $name;
        return $this;
    }

    public function withRow(RecipeRowDTO $row): self
    {
        $this->rows[] = $row;
        return $this;
    }

    public function build(): RecipeDTO
    {
        return new RecipeDTO($this->id, $this->name, $this->serving, $this->typeSlug, $this->typeName, $this->rows);
    }
}
