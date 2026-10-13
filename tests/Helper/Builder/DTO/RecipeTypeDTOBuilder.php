<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Tests\Helper\Builder\Builder;

class RecipeTypeDTOBuilder implements Builder
{
    private string $slug;
    private string $name;
    private string $image;
    private int $order;

    private function initialize(): void
    {
        $this->slug ??= 'plat';
        $this->name ??= 'plat';
        $this->image ??= 'images/recipe/type/meal.svg';
        $this->order ??= 2;
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

    public function withImage(string $image): self
    {
        $this->image = $image;
        return $this;
    }

    public function withOrder(int $order): self
    {
        $this->order = $order;
        return $this;
    }

    public function build(): RecipeTypeDTO
    {
        $this->initialize();
        return new RecipeTypeDTO($this->slug, $this->name, $this->image, $this->order);
    }
}
