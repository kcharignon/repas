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
    private int $version = 1;
    private bool $secret = false;
    private bool $favorite = false;
    private ?string $sourceUrl = null;
    private ?string $originalId = null;
    private bool $canEdit = true;
    private bool $ownerless = false;
    private ?string $copyId = null;

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

    public function withVersion(int $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function asSecret(): self
    {
        $this->secret = true;
        return $this;
    }

    public function asFavorite(): self
    {
        $this->favorite = true;
        return $this;
    }

    public function withSourceUrl(?string $sourceUrl): self
    {
        $this->sourceUrl = $sourceUrl;
        return $this;
    }

    public function withOriginalId(?string $originalId): self
    {
        $this->originalId = $originalId;
        return $this;
    }

    public function withoutEditRight(): self
    {
        $this->canEdit = false;
        return $this;
    }

    public function asOwnerless(?string $copyId = null): self
    {
        $this->ownerless = true;
        $this->copyId = $copyId;
        return $this;
    }

    public function withRow(RecipeRowDTO $row): self
    {
        $this->rows[] = $row;
        return $this;
    }

    public function build(): RecipeDTO
    {
        return new RecipeDTO($this->id, $this->name, $this->serving, $this->typeSlug, $this->typeName, $this->secret, $this->favorite, $this->rows, $this->version, $this->sourceUrl, $this->originalId, $this->canEdit, $this->ownerless, $this->copyId);
    }
}
