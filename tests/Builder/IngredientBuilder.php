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
        $this->name = 'pate';
        $this->slug = StringTool::slugify($this->name);
        $this->image = '';
        $this->departmentBuilder = new DepartmentBuilder()->isCereal();
        $this->defaultCookingUnitBuilder = new UnitBuilder()->isGramme();
        $this->defaultPurchaseUnitBuilder = new UnitBuilder()->isGramme();
        return $this;
    }

    public function isEgg(): self
    {
        $this->name = 'œuf';
        $this->slug = StringTool::slugify($this->name);
        $this->image = '';
        $this->departmentBuilder = new DepartmentBuilder()->isMiscellaneous();
        $this->defaultCookingUnitBuilder = new UnitBuilder()->isPiece();
        $this->defaultPurchaseUnitBuilder = new UnitBuilder()->isPiece();
        return $this;
    }

    public function isThickCremeFraiche(): self
    {
        $this->name = 'crème fraiche épaisse';
        $this->slug = StringTool::slugify($this->name);
        $this->image = '';
        $this->departmentBuilder = new DepartmentBuilder()->isMiscellaneous();
        $this->defaultCookingUnitBuilder = new UnitBuilder()->isCentilitre();
        $this->defaultPurchaseUnitBuilder = new UnitBuilder()->isGramme();
        return $this;
    }

    public function isDicedBacon(): self
    {
        $this->name = 'lardon';
        $this->slug = StringTool::slugify($this->name);
        $this->image = '';
        $this->departmentBuilder = new DepartmentBuilder()->isMeat();
        $this->defaultCookingUnitBuilder = new UnitBuilder()->isGramme();
        $this->defaultPurchaseUnitBuilder = new UnitBuilder()->isGramme();
        return $this;
    }

    public function isParmesan(): self
    {
        $this->name = 'parmesan';
        $this->slug = StringTool::slugify($this->name);
        $this->image = '';
        $this->departmentBuilder = new DepartmentBuilder()->isCheese();
        $this->defaultCookingUnitBuilder = new UnitBuilder()->isGramme();
        $this->defaultPurchaseUnitBuilder = new UnitBuilder()->isGramme();
        return $this;
    }

    public function isBread(): self
    {
        $this->name = 'pain';
        $this->slug = StringTool::slugify($this->name);
        $this->image = 'image/pain.jpg';
        $this->departmentBuilder = new DepartmentBuilder()->isBakery();
        $this->defaultCookingUnitBuilder = new UnitBuilder()->isPiece();
        $this->defaultPurchaseUnitBuilder = new UnitBuilder()->isPiece();
        return $this;
    }

    public function isMilk(): self
    {
        $this->name = 'lait';
        $this->slug = StringTool::slugify($this->name);
        $this->image = '';
        $this->departmentBuilder = new DepartmentBuilder()->isMiscellaneous();
        $this->defaultCookingUnitBuilder = new UnitBuilder()->isLiter();
        $this->defaultPurchaseUnitBuilder = new UnitBuilder()->isLiter();
        return $this;
    }

}
