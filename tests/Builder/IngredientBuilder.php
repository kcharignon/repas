<?php

namespace Repas\Tests\Builder;


use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Domain\Tool\StringTool;

class IngredientBuilder implements Builder
{
    private string $slug;
    private string $name;
    private string $image;
    private DepartmentBuilder $departmentBuilder;
    private UnitBuilder $defaultCookingUnitBuilder;
    private UnitBuilder $defaultPurchaseUnitBuilder;

    private function initialize(): void
    {
        $this->slug ??= 'un-truc-immangeable';
        $this->name ??= 'Un truc immangeable';
        $this->image ??= 'file://images/default.jpg';
        $this->departmentBuilder ??= new DepartmentBuilder()->isConserve();
        $this->defaultCookingUnitBuilder ??= new UnitBuilder()->isPiece();
        $this->defaultPurchaseUnitBuilder ??= new UnitBuilder()->isPiece();
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->slug ??= StringTool::slugify($name);
        $this->image ??= "file://images/$this->slug.jpg";
        return $this;
    }

    public function build(): Ingredient
    {
        $this->initialize();
        return Ingredient::load([
            'slug' => $this->slug,
            'name' => $this->name,
            'image' => $this->image,
            'department' => $this->departmentBuilder->build(),
            'default_cooking_unit' => $this->defaultCookingUnitBuilder->build(),
            'default_purchase_unit' => $this->defaultPurchaseUnitBuilder->build(),
        ]);
    }

    public function setDepartment(DepartmentBuilder $departmentBuilder): self
    {
        $this->departmentBuilder = $departmentBuilder;
        return $this;
    }

    public function isPasta(): self
    {
        $this->slug = 'pate';
        $this->name = 'pate';
        $this->image = 'image/pate.jpg';
        $this->departmentBuilder->isCereal();
        $this->defaultCookingUnitBuilder->isGramme();
        $this->defaultPurchaseUnitBuilder->isGramme();
        return $this;
    }

}
