<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\StringTool;

class Ingredient implements ModelInterface
{
    use ModelTrait;

    public function __construct(
        private string     $slug,
        private string     $name,
        private string     $image,
        private Department $department,
        private Unit       $defaultCookingUnit,
        private Unit       $defaultPurchaseUnit,
    ) {
    }

    public function getId(): string
    {
        return $this->slug;
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

    public function getDepartment(): Department
    {
        return $this->department;
    }

    public function getDefaultPurchaseUnit(): Unit
    {
        return $this->defaultPurchaseUnit;
    }

    public function getDefaultCookingUnit(): Unit
    {
        return $this->defaultCookingUnit;
    }

    public function setName(string $name): Ingredient
    {
        $this->name = $name;
        return $this;
    }

    public function setImage(string $image): Ingredient
    {
        $this->image = $image;
        return $this;
    }

    public function setDepartment(Department $department): Ingredient
    {
        $this->department = $department;
        return $this;
    }

    public function setDefaultCookingUnit(Unit $defaultCookingUnit): Ingredient
    {
        $this->defaultCookingUnit = $defaultCookingUnit;
        return $this;
    }

    public function setDefaultPurchaseUnit(Unit $defaultPurchaseUnit): Ingredient
    {
        $this->defaultPurchaseUnit = $defaultPurchaseUnit;
        return $this;
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

    public static function load(array $datas): static
    {
        return new Ingredient(
            slug: $datas['slug'],
            name: $datas['name'],
            image: $datas['image'],
            department: static::loadModel($datas['department'], Department::class),
            defaultCookingUnit: static::loadModel($datas['default_cooking_unit'], Unit::class),
            defaultPurchaseUnit: static::loadModel($datas['default_purchase_unit'], Unit::class),
        );
    }
}
