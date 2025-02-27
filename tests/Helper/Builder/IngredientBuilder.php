<?php

namespace Repas\Tests\Helper\Builder;


use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Model\User;

class IngredientBuilder implements Builder
{
    private string $slug;
    private string $name;
    private string $image;
    private DepartmentBuilder $departmentBuilder;
    private UnitBuilder $defaultCookingUnitBuilder;
    private UnitBuilder $defaultPurchaseUnitBuilder;
    private UserBuilder|User|null $creator;
    /** @var Tab<Unit>  */
    private Tab $compatibleUnits;

    /**
     * @param array<Unit|UnitBuilder> $units
     */
    public function withCompatibleUnits(array $units): self
    {
        $this->compatibleUnits ??= Tab::newEmptyTyped(Unit::class);
        foreach ($units as $unit) {
            $unit = ($unit instanceof UnitBuilder) ? $unit->build() : $unit;

            if (!$this->compatibleUnits->find(fn(Unit $u) => $u->isEqual($unit))) {
                $this->compatibleUnits[] = $unit;
            }
        }
        return $this;
    }

    private function initialize(): void
    {
        $this->name ??= 'Un truc immangeable';
        $this->departmentBuilder ??= new DepartmentBuilder()->isConserve();
        $this->defaultCookingUnitBuilder ??= new UnitBuilder()->isUnite();
        $this->defaultPurchaseUnitBuilder ??= new UnitBuilder()->isUnite();
        $this->creator ??= null;
        $this->slug ??= $this->calculateSlug();
        $this->image ??= "file://images/$this->slug.jpg";
        if ($this->defaultCookingUnitBuilder->build()->isEqual($this->defaultPurchaseUnitBuilder->build())) {
            // Si les unités de cuisine et d'achat sont identiques
            $this->compatibleUnits ??= Tab::fromArray($this->defaultCookingUnitBuilder->build());
        } else {
            // Si les unités de cuisine et d'achat sont différentes
            $this->compatibleUnits ??= Tab::fromArray($this->defaultCookingUnitBuilder->build(), $this->defaultPurchaseUnitBuilder->build());
        }
    }



    public function withName(string $name): self
    {
        $this->name = $name;
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
            'creator' => $this->creator instanceof UserBuilder ? $this->creator->build() : $this->creator,
            'compatible_units' => $this->compatibleUnits,
        ]);
    }

    public function withDepartment(DepartmentBuilder $departmentBuilder): self
    {
        $this->departmentBuilder = $departmentBuilder;
        return $this;
    }

    public function onBabyDepartment(): self
    {
        $this->departmentBuilder ??= new DepartmentBuilder();
        $this->departmentBuilder->isBaby();
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
        $this->compatibleUnits = Tab::fromArray(
            new UnitBuilder()->isGramme()->build(),
            new UnitBuilder()->isKilo()->build(),
        );
        return $this;
    }

    public function isEgg(): self
    {
        $this->name = 'œuf';
        $this->slug = StringTool::slugify($this->name);
        $this->image = 'images/ingredient/egg.png';
        $this->departmentBuilder = new DepartmentBuilder()->isMiscellaneous();
        $this->defaultCookingUnitBuilder = new UnitBuilder()->isUnite();
        $this->defaultPurchaseUnitBuilder = new UnitBuilder()->isUnite();
        return $this;
    }

    public function isThickCremeFraiche(): self
    {
        $this->name = 'crème fraiche épaisse';
        $this->slug = StringTool::slugify($this->name);
        $this->image = '';
        $this->departmentBuilder = new DepartmentBuilder()->isMiscellaneous();
        $this->defaultCookingUnitBuilder = new UnitBuilder()->isCentiliter();
        $this->defaultPurchaseUnitBuilder = new UnitBuilder()->isCentiliter();
        $this->compatibleUnits = Tab::fromArray(
            new UnitBuilder()->isLiter()->build(),
            new UnitBuilder()->isMillilitre()->build(),
            new UnitBuilder()->isCentiliter()->build(),
            new UnitBuilder()->isSoupSpoon()->build(),
            new UnitBuilder()->isCoffeeSpoon()->build(),
            new UnitBuilder()->isCoffeeCup()->build(),
            new UnitBuilder()->isGlass()->build(),
            new UnitBuilder()->isBowl()->build(),
            new UnitBuilder()->isGramme()->build(),
            new UnitBuilder()->isKilo()->build(),
        );
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
        $this->compatibleUnits = Tab::fromArray(
            new UnitBuilder()->isGramme()->build(),
            new UnitBuilder()->isKilo()->build(),
        );
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
        $this->compatibleUnits = Tab::fromArray(
            new UnitBuilder()->isGramme()->build(),
            new UnitBuilder()->isKilo()->build(),
        );
        return $this;
    }

    public function isBread(): self
    {
        $this->name = 'pain';
        $this->slug = StringTool::slugify($this->name);
        $this->image = 'image/pain.jpg';
        $this->departmentBuilder = new DepartmentBuilder()->isBakery();
        $this->defaultCookingUnitBuilder = new UnitBuilder()->isUnite();
        $this->defaultPurchaseUnitBuilder = new UnitBuilder()->isUnite();
        return $this;
    }

    public function isMilk(): self
    {
        $this->name = 'lait';
        $this->slug = StringTool::slugify($this->name);
        $this->image = 'images/ingredient/milk.png';
        $this->departmentBuilder = new DepartmentBuilder()->isMiscellaneous();
        $this->defaultCookingUnitBuilder = new UnitBuilder()->isLiter();
        $this->defaultPurchaseUnitBuilder = new UnitBuilder()->isLiter();
        $this->compatibleUnits = Tab::fromArray(
            new UnitBuilder()->isLiter()->build(),
            new UnitBuilder()->isMillilitre()->build(),
            new UnitBuilder()->isCentiliter()->build(),
            new UnitBuilder()->isSoupSpoon()->build(),
            new UnitBuilder()->isCoffeeSpoon()->build(),
            new UnitBuilder()->isCoffeeCup()->build(),
            new UnitBuilder()->isGlass()->build(),
            new UnitBuilder()->isBowl()->build(),
        );
        return $this;
    }

    public function withCreator(User|UserBuilder|null $creator, bool $refreshSlug = false): self
    {
        $this->creator = $creator;
        if ($refreshSlug) {
            $this->slug = $this->calculateSlug();
        }
        return $this;
    }

    public function withImage(string $image): self
    {
        $this->image = $image;
        return $this;
    }

    public function withDefaultCookingUnit(UnitBuilder $isUnite): self
    {
        $this->defaultCookingUnitBuilder = $isUnite;
        return $this;
    }

    public function withDefaultPurchaseUnit(UnitBuilder $isUnite): self
    {
        $this->defaultPurchaseUnitBuilder = $isUnite;
        return $this;
    }

    private function calculateSlug(): string
    {
        if ($this->creator ?? false) {
            return StringTool::slugify($this->name.$this->creator->getId());
        }
        return StringTool::slugify($this->name);
    }

    public function isFloor(): self
    {
        $this->name = 'farine';
        $this->slug = StringTool::slugify($this->name);
        $this->image = '';

        $this->departmentBuilder = new DepartmentBuilder()->isBakery();
        $this->defaultCookingUnitBuilder = new UnitBuilder()->isGramme();
        $this->defaultPurchaseUnitBuilder = new UnitBuilder()->isKilo();
        return $this;
    }

    public function isSugar(): self
    {
        $this->name = 'sucre';
        $this->slug = StringTool::slugify($this->name);
        $this->image = '';

        $this->departmentBuilder = new DepartmentBuilder()->isBakery();
        $this->defaultCookingUnitBuilder = new UnitBuilder()->isGramme();
        $this->defaultPurchaseUnitBuilder = new UnitBuilder()->isKilo();
        return $this;
    }

    public function isButter(): self
    {
        $this->name = 'beurre';
        $this->slug = StringTool::slugify($this->name);
        $this->image = 'images/ingredient/spread.png';

        $this->departmentBuilder = new DepartmentBuilder()->isCheese();
        $this->defaultCookingUnitBuilder = new UnitBuilder()->isGramme();
        $this->defaultPurchaseUnitBuilder = new UnitBuilder()->isBlock();
        $this->compatibleUnits = Tab::fromArray(
            new UnitBuilder()->isBlock()->build(),
            new UnitBuilder()->isGramme()->build(),
            new UnitBuilder()->isKilo()->build(),
        );
        return $this;
    }

    public function withSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }
}
