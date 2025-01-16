<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Ingredient as IngredientModel;
use Repas\Repository\IngredientRepository;

#[ORM\Entity(repositoryClass: IngredientRepository::class)]
#[ORM\Table('`ingredient`')]
class Ingredient
{
    #[ORM\Id]
    #[ORM\Column(name: 'slug', length: 255)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 2048)]
    private ?string $image = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'department', referencedColumnName: 'slug', nullable: false)]
    private ?Department $department = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'default_cooking_unit', referencedColumnName: 'slug', nullable: false)]
    private ?Unit $defaultCookingUnit = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'default_purchase_unit', referencedColumnName: 'slug', nullable: false)]
    private ?Unit $defaultPurchaseUnit = null;

    public function __construct(
        ?string $slug,
        ?string $name,
        ?string $image,
        ?Department $department,
        ?Unit $defaultCookingUnit,
        ?Unit $defaultPurchaseUnit,
    ) {
        $this->slug = $slug;
        $this->name = $name;
        $this->image = $image;
        $this->department = $department;
        $this->defaultCookingUnit = $defaultCookingUnit;
        $this->defaultPurchaseUnit = $defaultPurchaseUnit;
    }


    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): void
    {
        $this->image = $image;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): void
    {
        $this->department = $department;
    }

    public function getDefaultCookingUnit(): ?Unit
    {
        return $this->defaultCookingUnit;
    }

    public function setDefaultCookingUnit(?Unit $defaultCookingUnit): static
    {
        $this->defaultCookingUnit = $defaultCookingUnit;

        return $this;
    }

    public function getDefaultPurchaseUnit(): ?Unit
    {
        return $this->defaultPurchaseUnit;
    }

    public function setDefaultPurchaseUnit(?Unit $defaultPurchaseUnit): void
    {
        $this->defaultPurchaseUnit = $defaultPurchaseUnit;
    }

    public static function fromModel(IngredientModel $ingredient): static
    {
        $datas = $ingredient->toArray();
        return Ingredient::fromData($datas);
    }

    public function getModel(): IngredientModel
    {
        return IngredientModel::load([
            'slug' => $this->slug,
            'name' => $this->name,
            'image' => $this->image,
            'department' => $this->department,
            'defaultCookingUnit' => $this->defaultCookingUnit,
            'defaultPurchaseUnit' => $this->defaultPurchaseUnit,
        ]);
    }

    public static function fromData(array $datas): static
    {
        return new static(
            $datas['slug'],
            $datas['name'],
            $datas['image'],
            Department::fromData($datas['department']),
            Unit::fromData($datas['defaultCookingUnit']),
            Unit::fromData($datas['defaultPurchaseUnit']),
        );
    }
}
