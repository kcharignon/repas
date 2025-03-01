<?php

namespace Repas\Tests\Helper\Builder;



use Repas\Repas\Domain\Model\RecipeType;
use Repas\Shared\Domain\Tool\StringTool;

class RecipeTypeBuilder implements Builder
{
    private ?string $slug = null;
    private ?string $name = null;
    private ?string $image = null;
    private ?int $order = null;

    public function build(): RecipeType
    {
        $this->initialize();
        return RecipeType::load([
            'slug' => $this->slug,
            'name' => $this->name,
            'image' => $this->image,
            'order' => $this->order,
        ]);
    }

    private function initialize(): void
    {
        $this->name ??= 'plat';
        $this->slug ??= StringTool::slugify($this->name);
        $this->image ??= 'images/recipe/type/meal.svg';
        $this->order ??= 2;
    }

    public function isMeal(): self
    {
        $this->slug = 'plat';
        $this->name = 'plat';
        $this->image = 'images/recipe/type/meal.svg';
        $this->order = 2;
        return $this;
    }

    public function isDessert(): self
    {
        $this->slug = 'dessert';
        $this->name = 'dessert';
        $this->image = 'images/recipe/type/dessert.svg';
        $this->order = 3;
        return $this;
    }

    public function isStarter(): self
    {
        $this->slug = 'entrée';
        $this->name = 'entree';
        $this->image = 'images/recipe/type/starter.svg';
        $this->order = 1;
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

    public function withSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }
}
