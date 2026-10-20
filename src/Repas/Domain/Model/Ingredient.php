<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Shared\Domain\Tool\Tab;

final class Ingredient implements ModelInterface
{
    use ModelTrait;

    /**
     * @param Tab<Unit> $compatibleUnits
     */
    public function __construct(
        private string     $slug,
        private string     $name,
        private string     $image,
        private string     $sluggedName,
        private Department $department,
        private Unit       $defaultCookingUnit,
        private Unit       $defaultPurchaseUnit,
        private ?string    $creatorId,
        private Tab        $compatibleUnits,
        private ?string    $adoptedFrom = null,
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

    public function getSluggedName(): string
    {
        return $this->sluggedName;
    }

    public function setSluggedName(string $sluggedName): Ingredient
    {
        $this->sluggedName = $sluggedName;
        return $this;
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

    /**
     * Identifiant de l'utilisateur qui a créé l'ingrédient, `null` pour un ingrédient commun (ADR 0013 : un agrégat référence
     * un autre par identifiant).
     */
    public function getCreatorId(): ?string
    {
        return $this->creatorId;
    }

    public function setCreatorId(?string $creatorId): Ingredient
    {
        $this->creatorId = $creatorId;
        return $this;
    }

    /**
     * Slug du commun dont cet ingrédient est l'adoption (ADR 0028), `null` pour un ingrédient créé ou copié avec une recette. Un fait
     * immuable : l'ingrédient est né de cette adoption, quoi qu'il devienne ensuite (renommé, fusionné). L'association actuelle
     * « pour cet utilisateur, ce commun est cet ingrédient » est dans `IngredientAlias`, qui suit les fusions.
     */
    public function getAdoptedFrom(): ?string
    {
        return $this->adoptedFrom;
    }

    /**
     * Une adoption n'est pas une création (ADR 0028 décision 7) : elle n'entre pas dans la statistique « ingrédients créés ».
     */
    public function isAdoption(): bool
    {
        return $this->adoptedFrom !== null;
    }

    public function hasSameUnitInCookingAndPurchase(): bool
    {
        return $this->defaultCookingUnit->isEqual($this->defaultPurchaseUnit);
    }

    /**
     * @return Tab<Unit>
     */
    public function getCompatibleUnits(): Tab
    {
        return $this->compatibleUnits;
    }

    /**
     * @param Tab<Unit> $compatibleUnits
     */
    public function setCompatibleUnits(Tab $compatibleUnits): Ingredient
    {
        $this->compatibleUnits = $compatibleUnits;
        return $this;
    }

    public static function create(
        string $name,
        string $image,
        Department $department,
        Unit $defaultCookingUnit,
        Unit $defaultPurchaseUnit,
        ?string $creatorId,
    ): self {
        $slug = StringTool::slugify($name.($creatorId ?? ''));
        $sluggedName = StringTool::slugify($name);
        return new self(
            $slug,
            $name,
            $image,
            $sluggedName,
            $department,
            $defaultCookingUnit,
            $defaultPurchaseUnit,
            $creatorId,
            Tab::newEmptyTyped(Unit::class),
        );
    }

    /**
     * La copie, pour `$authorId`, d'un ingrédient qui accompagne la copie d'une recette (ADR 0028 décision 8) : mêmes nom, image, rayon et
     * unités ; les conversions propres sont copiées par le cas d'usage. Le slug est choisi par l'appelant (`IngredientSlugPolicy`, après
     * avoir vérifié qu'il est libre) : une copie n'écrase JAMAIS un ingrédient existant de `$authorId`.
     *
     * La provenance voyage : la copie d'une adoption garde le commun dont elle vient, et la copie d'un commun (quand le copieur ne peut
     * pas s'en servir tel quel) le retient aussi. Une copie qui retient une origine n'est pas une création (`isAdoption()`).
     */
    public static function copyFromOriginal(Ingredient $ingredient, string $authorId, string $slug): self
    {
        return new self(
            slug: $slug,
            name: $ingredient->getName(),
            image: $ingredient->getImage(),
            sluggedName: $ingredient->getSluggedName(),
            department: $ingredient->getDepartment(),
            defaultCookingUnit: $ingredient->getDefaultCookingUnit(),
            defaultPurchaseUnit: $ingredient->getDefaultPurchaseUnit(),
            creatorId: $authorId,
            compatibleUnits: $ingredient->getCompatibleUnits()->slice(0, null, true),
            adoptedFrom: $ingredient->getAdoptedFrom() ?? ($ingredient->getCreatorId() === null ? $ingredient->getSlug() : null),
        );
    }

    /**
     * L'adoption d'un commun par un utilisateur (ADR 0028) : une copie à lui, modifiable, qui garde le nom, l'image, le rayon et les
     * unités du commun. Le slug est choisi par l'appelant (`IngredientSlugPolicy::forAdoption()`, après avoir vérifié qu'il est libre) ;
     * les conversions propres du commun sont copiées par le cas d'usage. Comme `copyFromOriginal()`, elle retient d'où elle vient.
     */
    public static function adopt(Ingredient $common, string $userId, string $slug): self
    {
        return new self(
            slug: $slug,
            name: $common->getName(),
            image: $common->getImage(),
            sluggedName: $common->getSluggedName(),
            department: $common->getDepartment(),
            defaultCookingUnit: $common->getDefaultCookingUnit(),
            defaultPurchaseUnit: $common->getDefaultPurchaseUnit(),
            creatorId: $userId,
            // Une copie du tableau : l'ingrédient adopté ne partage pas son tableau d'unités avec le commun
            compatibleUnits: $common->getCompatibleUnits()->slice(0, null, true),
            adoptedFrom: $common->getSlug(),
        );
    }

    public static function load(array $datas): self
    {
        return new Ingredient(
            slug: $datas['slug'],
            name: $datas['name'],
            image: $datas['image'],
            sluggedName: $datas['slugged_name'],
            department: $datas['department'],
            defaultCookingUnit: $datas['default_cooking_unit'],
            defaultPurchaseUnit: $datas['default_purchase_unit'],
            creatorId: $datas['creator_id'],
            compatibleUnits: $datas['compatible_units'],
            adoptedFrom: $datas['adopted_from'],
        );
    }

    /**
     * @param Tab<Unit> $compatibleUnits
     */
    public function update(
        string $name,
        string $image,
        Department $department,
        Unit $defaultCookingUnit,
        Unit $defaultPurchaseUnit,
        Tab $compatibleUnits,
    ): void {
        $this->name = $name;
        $this->image = $image;
        $this->sluggedName = StringTool::slugify($name);
        $this->department = $department;
        $this->defaultCookingUnit = $defaultCookingUnit;
        $this->defaultPurchaseUnit = $defaultPurchaseUnit;
        $this->compatibleUnits = $compatibleUnits;
    }
}
