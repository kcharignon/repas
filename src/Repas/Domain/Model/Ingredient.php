<?php

namespace Repas\Repas\Domain\Model;


use Repas\Repas\Domain\Exception\IngredientException;
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
        private bool       $isBase = false,
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
     * Identifiant de l'utilisateur qui possède l'ingrédient (ADR 0013 : un agrégat référence un autre par identifiant). Tout ingrédient a un
     * propriétaire depuis la passation des anciens communs au propriétaire du catalogue (ADR 0028, amendement du 2026-10-20) ; le type reste
     * nullable tant que la colonne l'est (ING-014 le resserre).
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

    public function isOwnedBy(string $userId): bool
    {
        return $this->creatorId === $userId;
    }

    /**
     * L'ingrédient est à cet utilisateur, sans exception : pas même pour un administrateur. Ni la fusion de deux ingrédients ni les
     * conversions n'ont de passe-droit (ADR 0028 décision 9 et amendement, D3). L'ingrédient d'un autre est traité comme inconnu : on ne
     * révèle pas son existence.
     *
     * @throws IngredientException
     */
    public function assertOwnedBy(string $userId): void
    {
        if (!$this->isOwnedBy($userId)) {
            throw IngredientException::notFound($this->slug);
        }
    }

    /**
     * Slug de l'ingrédient dont celui-ci est la copie (ADR 0028) : celui d'un ingrédient du catalogue copié à l'activation du compte (ou,
     * avant la passation, d'un ancien commun), ou celui qui a été copié avec une recette ; `null` pour un ingrédient créé par son
     * propriétaire. Un fait immuable : l'ingrédient est né de cette copie, quoi qu'il devienne ensuite (renommé, fusionné).
     * L'association actuelle « pour cet utilisateur, cette origine est cet ingrédient » est dans `IngredientAlias`, qui suit les fusions.
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

    /**
     * Le marqueur « de base » (ADR 0028, amendement du 2026-10-20) : l'ingrédient part aux nouveaux comptes. Il n'a de sens que chez le
     * propriétaire du catalogue ; toute copie (création, adoption, copie de recette, amorçage) naît à `false`, et `update()` n'y touche pas.
     */
    public function isBase(): bool
    {
        return $this->isBase;
    }

    public function changeBase(bool $isBase): Ingredient
    {
        $this->isBase = $isBase;
        return $this;
    }

    /**
     * Seul le propriétaire du catalogue (`$catalogOwnerId`, `null` s'il n'existe pas : alors personne) pose le marqueur « de base », et
     * seulement sur un ingrédient qui est à lui. Le marqueur d'un autre compte serait de toute façon inerte (l'amorçage ne lit que le
     * catalogue du propriétaire) : le refuser vaut mieux que l'ignorer en silence.
     *
     * @throws IngredientException
     */
    public function assertBaseMarkerSettableBy(string $userId, ?string $catalogOwnerId): void
    {
        if ($catalogOwnerId === null || $userId !== $catalogOwnerId || $this->creatorId !== $userId) {
            throw IngredientException::baseMarkerForbidden();
        }
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

    /**
     * Ajoute des unités compatibles, sans doublon et sans toucher à celles qui existent (la fusion en apporte : `IngredientMergePlanner`).
     *
     * @param iterable<Unit> $units
     */
    public function addCompatibleUnits(iterable $units): Ingredient
    {
        foreach ($units as $unit) {
            if ($this->compatibleUnits->find(static fn(Unit $known): bool => $known->isEqual($unit)) === null) {
                $this->compatibleUnits[] = $unit;
            }
        }

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
     * La provenance voyage, pour TOUTE copie (ADR 0028, amendement, D7) : la copie d'une copie garde l'origine de l'original (`adopted_from`),
     * et la copie d'un ingrédient qui n'a pas d'origine le retient lui-même (son slug). Une copie retient donc toujours une origine : elle
     * n'est jamais une création (`isAdoption()`), la statistique « ingrédients créés » ne la compte pas.
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
            adoptedFrom: $ingredient->getAdoptedFrom() ?? $ingredient->getSlug(),
            // Une copie naît toujours à faux, quelle que soit la source : le marqueur « de base » ne se copie jamais (ADR 0028, amendement)
            isBase: false,
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
            // Une copie naît toujours à faux, quelle que soit la source : le marqueur « de base » ne se copie jamais (ADR 0028, amendement)
            isBase: false,
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
            isBase: $datas['is_base'] ?? false,
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
