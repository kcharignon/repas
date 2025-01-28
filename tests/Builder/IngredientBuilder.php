<?php

namespace Repas\Tests\Builder;


use Repas\Repas\Domain\Model\Ingredient;

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
        $this->departmentBuilder ??= new DepartmentBuilder()->setName('Conserve');
        $this->defaultCookingUnitBuilder ??= new UnitBuilder()->setName('piece');
        $this->defaultPurchaseUnitBuilder ??= new UnitBuilder()->setName('piece');
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

}
