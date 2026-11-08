<?php

namespace Repas\Repas\Domain\Model;


use LogicException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Shared\Domain\Tool\Tab;

final class Ingredient implements ModelInterface
{
    use ModelTrait;

    /**
     * Nombre d'ingrédients à partir duquel un compte n'en crée plus lui-même : tous les siens comptent (copies et amorçage compris), mais seule la
     * création est refusée, jamais l'amorçage à l'activation (261 ingrédients) ni la copie d'une recette. Marge : 1 739 créations après l'amorçage.
     */
    public const int MAX_PER_CREATOR = 2000;

    /**
     * Nombre de parties qu'un même ingrédient peut avoir (ADR 0038 décision 2) : un œuf a un jaune et un blanc, personne n'a demandé plus de
     * dix. Le contrôle est un test d'existence de la dixième partie (`IngredientRepository::partsHaveAtLeast`) : si elle existe, le parent est plein ; jamais un décompte.
     */
    public const int MAX_PARTS = 10;

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
        private string     $creatorId,
        private Tab        $compatibleUnits,
        private ?string    $adoptedFrom = null,
        private bool       $isBase = false,
        private ?string    $listDestination = null,
        private ?string    $parentSlug = null,
        private ?float     $parentRatio = null,
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
     * propriétaire depuis la passation des anciens communs au propriétaire du catalogue (ADR 0028, amendement du 2026-10-20) ; seule la colonne
     * reste nullable (le code précédent la lit encore), `load()` refuse une ligne sans créateur.
     */
    public function getCreatorId(): string
    {
        return $this->creatorId;
    }

    public function setCreatorId(string $creatorId): Ingredient
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

    /**
     * Le choix de l'utilisateur sur la liste où va ce produit (ADR 0036) : `null` = « comme son rayon », sinon la valeur réservée `main` (la
     * liste principale) ou l'identifiant d'un type de liste de son propriétaire. Un choix PERSONNEL : une copie du produit (copie de recette,
     * amorçage d'un compte) ne le porte jamais, la fusion garde celui du produit conservé. Rien ne le lit encore (LST-003).
     */
    public function getListDestination(): ?string
    {
        return $this->listDestination;
    }

    /**
     * Pose ou efface (`null`) le choix de liste du produit. Seule la suppression d'un type l'efface pour l'instant (en masse en base,
     * `IngredientRepository::clearListDestination`) ; le cas d'usage qui le choisit à la main est LST-003.
     */
    public function changeListDestination(?string $listDestination): Ingredient
    {
        $this->listDestination = $listDestination;
        return $this;
    }

    /**
     * Le slug de l'ingrédient dont celui-ci est une PARTIE (ADR 0038 : « Jaune d'œuf » est une partie d'« Œuf »), `null` pour un ingrédient
     * ordinaire. Un seul parent, profondeur 1 : un parent n'est pas une partie. Un choix PERSONNEL de l'utilisateur : une copie de
     * l'ingrédient (copie de recette, amorçage d'un compte) ne le porte jamais, le lien se refait par provenance (SUB-002).
     */
    public function getParentSlug(): ?string
    {
        return $this->parentSlug;
    }

    /**
     * Combien d'unités par défaut de cette partie font UNE unité de recette du parent (1 œuf = 1 jaune : `1` ; une gousse de vanille = deux
     * demi-gousses : `2`). `null` si l'ingrédient n'est pas une partie : le parent et le ratio sont posés ensemble ou pas du tout.
     */
    public function getParentRatio(): ?float
    {
        return $this->parentRatio;
    }

    public function isPart(): bool
    {
        return $this->parentSlug !== null;
    }

    /**
     * Fait de cet ingrédient une partie de `$parentSlug` (ou change son parent ou son ratio). Ne juge que ce que l'ingrédient sait seul : pas
     * lui-même, un ratio fini de 0,000001 à 1 000 000. Ce qui suppose d'autres ingrédients (le parent est à l'utilisateur, n'est pas une
     * partie, la partie n'a pas de partie, le plafond de dix, les listes en cours) est jugé par le cas d'usage AVANT cet appel.
     *
     * @throws IngredientException `partOfItself()` ou `partRatioInvalid()`
     */
    public function makePartOf(string $parentSlug, float $ratio): Ingredient
    {
        if ($parentSlug === $this->slug) {
            throw IngredientException::partOfItself();
        }
        if (!QuantityBounds::isCoefficient($ratio)) {
            throw IngredientException::partRatioInvalid();
        }

        $this->parentSlug = $parentSlug;
        $this->parentRatio = $ratio;

        return $this;
    }

    /**
     * L'ingrédient redevient ordinaire : ni parent ni ratio (toujours ensemble).
     */
    public function detachFromParent(): Ingredient
    {
        $this->parentSlug = null;
        $this->parentRatio = null;

        return $this;
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
        string $creatorId,
        ?string $slug = null,
    ): self {
        // Sans slug donné : celui du nom et du créateur. Le cas d'usage de création en donne un libre (`IngredientSlugPolicy`), car deux
        // ingrédients du même propriétaire peuvent porter le même nom dans deux rayons différents (ADR 0028, amendement du 2026-10-30).
        $slug ??= StringTool::slugify($name.$creatorId);
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
     *
     * Une copie ne recopie JAMAIS `parent_slug` ni `parent_ratio` (ADR 0038 décision 5) : le slug du parent de l'original est celui d'un
     * ingrédient d'un AUTRE compte. Le lien se refait par provenance (SUB-002), ou n'existe pas.
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
     * @throws LogicException un ingrédient sans créateur : la base n'a pas passé la passation des anciens communs (ADR 0028, amendement du
     *                         2026-10-20), ce code ne la lit pas ; `docs/ingredients/sans-commun.md`.
     */
    public static function load(array $datas): self
    {
        if (empty($datas['creator_id'])) {
            throw new LogicException(sprintf("L'ingrédient « %s » n'a pas de créateur : la passation des anciens communs n'est pas faite, voir docs/ingredients/sans-commun.md.", $datas['slug'] ?? '?'));
        }

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
            listDestination: $datas['list_destination'] ?? null,
            parentSlug: $datas['parent_slug'] ?? null,
            parentRatio: $datas['parent_ratio'] ?? null,
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
