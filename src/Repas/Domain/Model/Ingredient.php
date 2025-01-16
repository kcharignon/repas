<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Tool\StringTool;

class Ingredient
{
    public function __construct(
        private string     $slug,
        private string     $name,
        private string     $image,
        private Department $department,
        private Unit       $defaultCookingUnit,
        private Unit       $defaultPurchaseUnit,
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
        Department $department,
        Unit $defaultCookingUnit,
        Unit $defaultPurchaseUnit,
    ): self {
        $slug = StringTool::slugify($name);
        return new self(
            $slug,
            $name,
            $image,
            $department,
            $defaultCookingUnit,
            $defaultPurchaseUnit
        );
    }

    public static function load(array $ingredientData): self
    {
        return new Ingredient(
            $ingredientData['slug'],
            $ingredientData['name'],
            $ingredientData['image'],
            Department::load($ingredientData['department']),
            Unit::load($ingredientData['defaultCookingUnit']),
            Unit::load($ingredientData['defaultPurchaseUnit']),
        );
    }

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'image' => $this->image,
            'department' => $this->department->toArray(),
            'defaultCookingUnit' => $this->defaultCookingUnit->toArray(),
            'defaultPurchaseUnit' => $this->defaultPurchaseUnit->toArray(),
        ];
    }
}
