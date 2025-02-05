<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Ingredient as IngredientModel;

#[ORM\Entity]
#[ORM\Table(name: 'ingredient')]
class Ingredient
{
    #[ORM\Id]
    #[ORM\Column(name: 'slug', length: 255)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 2048)]
    private ?string $image = null;

    #[ORM\Column(name: 'department', nullable: false)]
    private ?string $departmentSlug = null;

    #[ORM\Column(name: 'default_cooking_unit', nullable: false)]
    private ?string $defaultCookingUnitSlug = null;

    #[ORM\Column(name: 'default_purchase_unit', nullable: false)]
    private ?string $defaultPurchaseUnitSlug = null;

    #[ORM\Column(name: 'creator', nullable: true)]
    private ?string $creatorId = null;

    public function __construct(
        ?string $slug,
        ?string $name,
        ?string $image,
        ?string $department,
        ?string $defaultCookingUnit,
        ?string $defaultPurchaseUnit,
        ?string $creatorId,
    ) {
        $this->slug = $slug;
        $this->name = $name;
        $this->image = $image;
        $this->departmentSlug = $department;
        $this->defaultCookingUnitSlug = $defaultCookingUnit;
        $this->defaultPurchaseUnitSlug = $defaultPurchaseUnit;
        $this->creatorId = $creatorId;
    }


    public function getSlug(): ?string
    {
        return $this->slug;
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

    public function getDepartmentSlug(): ?string
    {
        return $this->departmentSlug;
    }

    public function getDefaultCookingUnitSlug(): ?string
    {
        return $this->defaultCookingUnitSlug;
    }

    public function getDefaultPurchaseUnitSlug(): ?string
    {
        return $this->defaultPurchaseUnitSlug;
    }

    public function setImage(?string $image): Ingredient
    {
        $this->image = $image;
        return $this;
    }

    public function setDepartmentSlug(?string $departmentSlug): Ingredient
    {
        $this->departmentSlug = $departmentSlug;
        return $this;
    }

    public function setDefaultCookingUnitSlug(?string $defaultCookingUnitSlug): Ingredient
    {
        $this->defaultCookingUnitSlug = $defaultCookingUnitSlug;
        return $this;
    }

    public function setDefaultPurchaseUnitSlug(?string $defaultPurchaseUnitSlug): Ingredient
    {
        $this->defaultPurchaseUnitSlug = $defaultPurchaseUnitSlug;
        return $this;
    }

    public function getCreatorId(): ?string
    {
        return $this->creatorId;
    }

    public function setCreatorId(?string $creatorId): Ingredient
    {
        $this->creatorId = $creatorId;
        return $this;
    }

    public static function fromModel(IngredientModel $ingredient): static
    {
        return new static(
            $ingredient->getSlug(),
            $ingredient->getName(),
            $ingredient->getImage(),
            $ingredient->getDepartment()->getSlug(),
            $ingredient->getDefaultCookingUnit()->getSlug(),
            $ingredient->getDefaultPurchaseUnit()->getSlug(),
            $ingredient->getCreator()?->getId()
        );
    }
}
