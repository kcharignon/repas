<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Tool\StringTool;

class Ingredient
{
    public function __construct(
        private string $slug,
        private string $name,
        private string $image,
        private Unit   $defaultCookingUnit,
        private Unit   $defaultPurchaseUnit,
    ) {
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getImage(): string
    {
        return $this->image;
    }

    public function getDefaultCookingUnit(): Unit
    {
        return $this->defaultCookingUnit;
    }

    public static function create(
        string $name,
        string $image,
        Unit $defaultCookingUnit,
        Unit $defaultPurchaseUnit,
    ): self {
        $slug = StringTool::slugify($name);
        return new self($slug, $name, $image, $defaultCookingUnit, $defaultPurchaseUnit);
    }

    public static function load(array $ingredientData): self
    {
        return new Ingredient(
            $ingredientData['slug'],
            $ingredientData['name'],
            $ingredientData['image'],
            $ingredientData['defaultCookingUnit'],
            $ingredientData['defaultPurchaseUnit'],
        );
    }

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'image' => $this->image,
            'defaultCookingUnit' => $this->defaultCookingUnit->toArray(),
            'defaultPurchaseUnit' => $this->defaultPurchaseUnit->toArray(),
        ];
    }


}
