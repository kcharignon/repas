<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\User\Domain\Model\User;

final class Ingredient implements ModelInterface
{
    use ModelTrait;

    public function __construct(
        private string     $slug,
        private string     $name,
        private string     $image,
        private Department $department,
        private Unit       $defaultCookingUnit,
        private Unit       $defaultPurchaseUnit,
        private ?User      $creator,
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

    public function getCreator(): ?User
    {
        return $this->creator;
    }

    public function setCreator(?User $creator): Ingredient
    {
        $this->creator = $creator;
        return $this;
    }

    public static function create(
        string $name,
        string $image,
        Department $department,
        Unit $defaultCookingUnit,
        Unit $defaultPurchaseUnit,
        ?User $creator,
    ): self {
        $slug = StringTool::slugify($name.($creator?->getId() ?? ''));
        return new self(
            $slug,
            $name,
            $image,
            $department,
            $defaultCookingUnit,
            $defaultPurchaseUnit,
            $creator,
        );
    }

    public static function load(array $datas): self
    {
        return new Ingredient(
            slug: $datas['slug'],
            name: $datas['name'],
            image: $datas['image'],
            department: $datas['department'],
            defaultCookingUnit: $datas['default_cooking_unit'],
            defaultPurchaseUnit: $datas['default_purchase_unit'],
            creator: $datas['creator'],
        );
    }

    public function update(
        string $name,
        string $image,
        Department $department,
        Unit $defaultCookingUnit,
        Unit $defaultPurchaseUnit,
    ): void {
        $this->name = $name;
        $this->image = $image;
        $this->department = $department;
        $this->defaultCookingUnit = $defaultCookingUnit;
        $this->defaultPurchaseUnit = $defaultPurchaseUnit;
    }
}
