<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Ingredient as IngredientModel;
use Repas\Repas\Domain\Model\Unit as UnitModel;

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

    #[ORM\Column(length: 255, nullable: false)]
    private ?string $sluggedName = null;

    #[ORM\Column(name: 'department', nullable: false)]
    private ?string $departmentSlug = null;

    #[ORM\Column(name: 'default_cooking_unit', nullable: false)]
    private ?string $defaultCookingUnitSlug = null;

    #[ORM\Column(name: 'default_purchase_unit', nullable: false)]
    private ?string $defaultPurchaseUnitSlug = null;

    #[ORM\Column(name: 'creator', nullable: true)]
    private ?string $creatorId = null;

    /** @var array<string>  */
    #[ORM\Column(name: 'compatible_units', type: 'json', nullable: false)]
    private array $compatibleUnitSlugs = [];

    /** L'ingrédient dont celui-ci est la copie (ADR 0028) : un fait immuable, jamais mis à jour après la création. */
    #[ORM\Column(name: 'adopted_from', length: 255, nullable: true)]
    private ?string $adoptedFrom = null;

    /**
     * Le marqueur « ingrédient de base » (ADR 0028, amendement du 2026-10-20) : l'ingrédient part aux nouveaux comptes. `NOT NULL`, faux par
     * défaut côté base : le code précédent, qui ne mappe pas la colonne, la laisse à sa valeur.
     */
    #[ORM\Column(name: 'is_base', type: 'boolean', options: ['default' => false])]
    private bool $isBase = false;

    /**
     * @param array<string> $compatibleUnitSlugs
     */
    public function __construct(
        ?string $slug,
        ?string $name,
        ?string $image,
        ?string $sluggedName,
        ?string $department,
        ?string $defaultCookingUnit,
        ?string $defaultPurchaseUnit,
        ?string $creatorId,
        array $compatibleUnitSlugs,
        ?string $adoptedFrom = null,
        bool $isBase = false,
    ) {
        $this->slug = $slug;
        $this->name = $name;
        $this->image = $image;
        $this->sluggedName = $sluggedName;
        $this->departmentSlug = $department;
        $this->defaultCookingUnitSlug = $defaultCookingUnit;
        $this->defaultPurchaseUnitSlug = $defaultPurchaseUnit;
        $this->creatorId = $creatorId;
        $this->compatibleUnitSlugs = $compatibleUnitSlugs;
        $this->adoptedFrom = $adoptedFrom;
        $this->isBase = $isBase;
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

    public function getSluggedName(): ?string
    {
        return $this->sluggedName;
    }

    public function setSluggedName(?string $sluggedName): Ingredient
    {
        $this->sluggedName = $sluggedName;
        return $this;
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

    public function getCompatibleUnitSlugs(): array
    {
        return $this->compatibleUnitSlugs;
    }

    /**
     * @param array<string> $compatibleUnitSlugs
     */
    public function setCompatibleUnitSlugs(array $compatibleUnitSlugs): Ingredient
    {
        $this->compatibleUnitSlugs = $compatibleUnitSlugs;
        return $this;
    }

    public function getAdoptedFrom(): ?string
    {
        return $this->adoptedFrom;
    }

    public function isBase(): bool
    {
        return $this->isBase;
    }

    public function setIsBase(bool $isBase): Ingredient
    {
        $this->isBase = $isBase;
        return $this;
    }

    public static function fromModel(IngredientModel $ingredient): static
    {
        return new static(
            slug: $ingredient->getSlug(),
            name: $ingredient->getName(),
            image: $ingredient->getImage(),
            sluggedName: $ingredient->getSluggedName(),
            department: $ingredient->getDepartment()->getSlug(),
            defaultCookingUnit: $ingredient->getDefaultCookingUnit()->getSlug(),
            defaultPurchaseUnit: $ingredient->getDefaultPurchaseUnit()->getSlug(),
            creatorId: $ingredient->getCreatorId(),
            compatibleUnitSlugs: $ingredient->getCompatibleUnits()->map(fn(UnitModel $unit) => $unit->getSlug())->toArray(),
            adoptedFrom: $ingredient->getAdoptedFrom(),
            isBase: $ingredient->isBase(),
        );
    }
}
