<?php

namespace Repas\Repas\Application\Service;


use Repas\Repas\Application\Port\CatalogOwnerCommandPort;
use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientMatchCandidates;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientCandidate;
use Repas\Repas\Domain\Model\MarmitonRecipePage;
use Repas\Repas\Domain\Model\MarmitonUrl;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Repas\Domain\Service\Marmiton\MarmitonIngredientLineParser;
use Repas\Repas\Domain\Service\Marmiton\MarmitonIngredientMatcher;
use Repas\Repas\Domain\Service\Marmiton\MarmitonRecipeCategory;
use Repas\Repas\Domain\Service\Marmiton\MarmitonRecipeTitle;
use Repas\Repas\Domain\Service\Marmiton\MarmitonServings;
use Repas\Repas\Domain\Service\Marmiton\ParsedIngredientLine;
use Repas\Shared\Domain\Interface\UuidGenerator;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Shared\Domain\Tool\Tab;

/**
 * Crée la recette Marmiton NEUTRE à partir d'une page lue (ADR 0037 décisions 1 à 8). La page est déjà lue : ce service ne contacte rien, il
 * transforme et écrit, dans la transaction de l'import (tout ou rien : une neutre n'est jamais à moitié remplie).
 *
 * - le nom, le nombre de personnes et le type viennent de `MarmitonRecipeTitle`, `MarmitonServings` et `MarmitonRecipeCategory` ; une ligne que
 *   `MarmitonIngredientLineParser` ne comprend pas est ignorée, et une page sans AUCUNE ligne lisible est une recette illisible (rien n'est écrit) ;
 * - chaque ligne reçoit un ingrédient du propriétaire « personne » (`Recipe::MARMITON_OWNER_ID`), dans cet ordre (décision 6) :
 *   (a) un ingrédient déjà créé pour les recettes Marmiton, de même nom normalisé ; (b) à défaut, UNE COPIE de l'ingrédient « de base » du
 *   catalogue de même nom (`RecipeCopier::copyIngredient` : rayon, unités, image et conversions propres repris, origine retenue dans
 *   `adopted_from`, ce qui fait que la copie d'un compte amorcé retrouve ses propres ingrédients par provenance) ; (c) sinon un NOUVEL ingrédient en
 *   rayon `divers`, dont l'unité d'achat et l'unité de recette sont celles de la ligne. Au moindre doute on crée (`MarmitonIngredientMatcher`) ;
 * - une ligne qui ne se convertit pas dans l'unité d'achat de son ingrédient n'empêche PAS la neutre (décision 8) : la fiche dit « à régler » ;
 * - aucun plafond de compte (`Ingredient::MAX_PER_CREATOR`), aucun événement, aucune statistique : le propriétaire n'est pas un compte. Le
 *   rayon `divers` n'est lu que si un ingrédient doit être créé.
 *
 * Les lectures ne dépendent pas du nombre de lignes (candidats, ingrédients, unités et types : une requête chacun) ; les écritures suivent le nombre
 * d'ingrédients créés ou copiés, comme toute copie d'ingrédients.
 */
readonly class MarmitonNeutralRecipeCreator
{
    /** Le rayon des ingrédients que Marmiton nomme et que le catalogue ne connaît pas. */
    private const string DEPARTMENT_SLUG = 'divers';

    /** L'unité d'une ligne dont l'unité reconnue n'existe pas (encore) en base : la ligne reste, comptée en unités. */
    private const string FALLBACK_UNIT_SLUG = 'unite';

    /** Les clés d'un ingrédient pas encore écrit : elles contiennent « : », qu'aucun slug d'ingrédient ne porte. */
    private const string CATALOG_PREFIX = 'catalog:';
    private const string NEW_PREFIX = 'new:';

    public function __construct(
        private RecipeRepository $recipeRepository,
        private RecipeTypeRepository $recipeTypeRepository,
        private IngredientRepository $ingredientRepository,
        private IngredientMatchCandidates $matchCandidates,
        private DepartmentRepository $departmentRepository,
        private UnitRepository $unitRepository,
        private CatalogOwnerCommandPort $catalogOwner,
        private MarmitonIngredientMatcher $matcher,
        private RecipeCopier $copier,
        private IngredientSlugPolicy $slugPolicy,
        private ConversionService $conversionService,
        private UuidGenerator $uuidGenerator,
    ) {
    }

    /**
     * @throws MarmitonImportException `recipeUnreadable` : le titre est vide une fois nettoyé, ou aucune ligne d'ingrédient n'est lisible
     * @throws RecipeException aucun type de recette n'existe
     * @throws UnitException une ligne dit une unité qui n'existe pas, et `unite` non plus
     */
    public function create(MarmitonUrl $url, MarmitonRecipePage $page): Recipe
    {
        // Tout ce qui peut refuser la page, AVANT la première écriture
        $title = MarmitonRecipeTitle::clean($page->title);
        if ($title === '') {
            throw MarmitonImportException::recipeUnreadable();
        }
        $lines = [];
        foreach ($page->ingredientLines as $rawLine) {
            $line = MarmitonIngredientLineParser::parse($rawLine);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        if ($lines === []) {
            throw MarmitonImportException::recipeUnreadable();
        }

        $canonical = MarmitonUrl::parse($page->canonicalUrl);
        if ($canonical->id !== $url->id) {
            // Le lecteur ne suit une redirection que vers le même identifiant : une autre recette n'est jamais enregistrée sous ce tag
            throw MarmitonImportException::unavailable();
        }

        $type = $this->typeOf(MarmitonRecipeCategory::typeSlug($page->category));
        /** @var array<string, Unit> $units */
        $units = $this->unitRepository->findAll()->indexBy(static fn(Unit $unit): string => $unit->getSlug());

        $recipeId = $this->uuidGenerator->generate();
        $ingredientOf = $this->ingredientsOf($lines, $units);
        $rows = Tab::newEmptyTyped(RecipeRow::class);
        foreach ($lines as $index => $line) {
            $rows[] = RecipeRow::create(
                id: $this->uuidGenerator->generate(),
                recipeId: $recipeId,
                ingredient: $ingredientOf[$index],
                quantity: $line->quantity,
                unit: $this->unitOf($line->unitSlug, $units),
            );
        }

        $recipe = Recipe::createMarmitonNeutral(
            id: $recipeId,
            // La première lettre du nom est mise en majuscule en silence, le reste est conservé (ADR 0030)
            name: StringTool::capitalizeFirst($title),
            servings: MarmitonServings::from($page->servingsText),
            recipeType: $type,
            rows: $rows,
            // L'adresse canonique est celle de la page lue, après la redirection éventuelle (un faux nom de recette est redirigé vers le bon)
            url: $canonical,
        );
        $this->recipeRepository->save($recipe);

        return $recipe;
    }

    /**
     * L'ingrédient de chaque ligne, dans l'ordre des lignes (décision 6 : a, b, c). Deux étapes : on décide pour chaque ligne (des lectures, en
     * une requête par sorte), puis on lit les ingrédients à réutiliser ou à copier en une requête, puis on écrit les copies et les créations.
     *
     * @param list<ParsedIngredientLine> $lines
     * @param array<string, Unit> $units
     * @return array<int, Ingredient> l'ingrédient de chaque ligne, par rang de ligne
     */
    private function ingredientsOf(array $lines, array $units): array
    {
        $ownerId = Recipe::MARMITON_OWNER_ID;
        $pool = $this->matchCandidates->visibleTo($ownerId);
        // Les copies déjà faites d'un ingrédient du catalogue, par origine : un ingrédient du catalogue n'est copié qu'une fois pour tous
        $copiedFrom = [];
        foreach ($pool as $candidate) {
            if ($candidate->adoptedFrom !== null) {
                $copiedFrom[$candidate->adoptedFrom] ??= $candidate->slug;
            }
        }

        /** @var list<IngredientCandidate>|null $catalog lu seulement si une ligne n'est pas déjà connue des recettes Marmiton */
        $catalog = null;
        /** @var array<string, array{kind: string, slug: string, name: string, unitSlug: string}> $plan ce qu'il faut pour chaque clé d'ingrédient */
        $plan = [];
        /** @var array<int, string> $keyOfLine */
        $keyOfLine = [];
        foreach ($lines as $index => $line) {
            $match = $this->matcher->match($line->name, $pool);
            if ($match !== null) {
                $key = $match->slug;
                $plan[$key] ??= ['kind' => 'existing', 'slug' => $key, 'name' => $line->name, 'unitSlug' => $line->unitSlug];
                $keyOfLine[$index] = $key;
                continue;
            }

            $catalog ??= $this->catalogCandidates();
            $inCatalog = $this->matcher->match($line->name, $catalog);
            if ($inCatalog !== null && isset($copiedFrom[$inCatalog->slug])) {
                // Le catalogue a déjà donné cet ingrédient aux recettes Marmiton (sous un nom que la ligne ne retrouve pas, ou dans un rayon
                // qui rend le nom ambigu) : on le réutilise au lieu d'en copier un autre
                $key = $copiedFrom[$inCatalog->slug];
                $plan[$key] ??= ['kind' => 'existing', 'slug' => $key, 'name' => $line->name, 'unitSlug' => $line->unitSlug];
            } elseif ($inCatalog !== null) {
                $key = self::CATALOG_PREFIX . $inCatalog->slug;
                $plan[$key] = ['kind' => 'catalog', 'slug' => $inCatalog->slug, 'name' => $line->name, 'unitSlug' => $line->unitSlug];
                // Les lignes suivantes de même nom retrouvent cet ingrédient plutôt que d'en copier un deuxième
                $pool[] = new IngredientCandidate($key, $inCatalog->name, $inCatalog->departmentSlug, $inCatalog->purchaseUnitSlug);
            } else {
                $key = self::NEW_PREFIX . count($plan);
                $plan[$key] = ['kind' => 'new', 'slug' => $key, 'name' => $line->name, 'unitSlug' => $line->unitSlug];
                $pool[] = new IngredientCandidate($key, $line->name, self::DEPARTMENT_SLUG, $this->unitOf($line->unitSlug, $units)->getSlug());
            }
            $keyOfLine[$index] = $key;
        }

        // Une seule lecture pour les ingrédients à réutiliser et ceux à copier
        $toLoad = array_values(array_unique(array_map(static fn(array $step): string => $step['slug'], array_filter($plan, static fn(array $step): bool => $step['kind'] !== 'new'))));
        /** @var array<string, Ingredient> $loaded */
        $loaded = [];
        if ($toLoad !== []) {
            foreach ($this->ingredientRepository->findBySlugs(new Tab($toLoad, 'string')) as $ingredient) {
                $loaded[$ingredient->getSlug()] = $ingredient;
            }
        }

        $department = null;
        /** @var array<string, Ingredient> $ingredients */
        $ingredients = [];
        foreach ($plan as $key => $step) {
            if ($step['kind'] === 'existing') {
                $ingredients[$key] = $loaded[$step['slug']];
            } elseif ($step['kind'] === 'catalog') {
                $ingredients[$key] = $this->copier->copyIngredient(Recipe::MARMITON_OWNER_ID, $loaded[$step['slug']]);
            } else {
                $department ??= $this->departmentRepository->findOneBySlug(self::DEPARTMENT_SLUG);
                $ingredients[$key] = $this->newIngredient($step['name'], $this->unitOf($step['unitSlug'], $units), $department);
            }
        }

        return array_map(static fn(string $key): Ingredient => $ingredients[$key], $keyOfLine);
    }

    /**
     * Les ingrédients « de base » du catalogue : ceux que reçoit un compte à son activation. Aucun propriétaire du catalogue, aucun candidat
     * (jamais une erreur : l'import reste possible, tout est alors créé en rayon `divers`).
     *
     * @return list<IngredientCandidate>
     */
    private function catalogCandidates(): array
    {
        $catalogOwnerId = $this->catalogOwner->ownerId();

        return $catalogOwnerId === null ? [] : $this->matchCandidates->baseOf($catalogOwnerId);
    }

    /**
     * (c) Un ingrédient du propriétaire « personne », en rayon `divers`, acheté et cuisiné dans l'unité de la ligne (ses unités compatibles sont
     * celles que les conversions communes atteignent depuis elle). Le nom passe par la règle de la première lettre (ADR 0030) ; aucun plafond ni
     * événement : ce n'est pas la création d'un compte.
     */
    private function newIngredient(string $name, Unit $unit, Department $department): Ingredient
    {
        $ownerId = Recipe::MARMITON_OWNER_ID;
        $ingredient = Ingredient::create(
            name: StringTool::capitalizeFirst($name),
            image: '',
            department: $department,
            defaultCookingUnit: $unit,
            defaultPurchaseUnit: $unit,
            slug: $this->slugPolicy->firstFreeSlug($name, $ownerId, fn(string $candidate): bool => $this->ingredientRepository->exists($candidate)),
            creatorId: $ownerId,
        );
        $ingredient->setCompatibleUnits($this->conversionService->getConvertibleUnits($ingredient, $unit));
        $this->ingredientRepository->save($ingredient);

        return $ingredient;
    }

    /**
     * Le type de recette de la catégorie de Marmiton ; s'il n'existe pas dans les données, le premier par ordre.
     *
     * @throws RecipeException aucun type de recette n'existe
     */
    private function typeOf(string $slug): RecipeType
    {
        $types = $this->recipeTypeRepository->findAll()->toArray();
        usort($types, static fn(RecipeType $first, RecipeType $second): int => [$first->getOrder(), $first->getSlug()] <=> [$second->getOrder(), $second->getSlug()]);
        foreach ($types as $type) {
            if ($type->getSlug() === $slug) {
                return $type;
            }
        }

        return $types[0] ?? throw RecipeException::typeNotFound($slug);
    }

    /**
     * @param array<string, Unit> $units
     * @throws UnitException
     */
    private function unitOf(string $slug, array $units): Unit
    {
        return $units[$slug] ?? $units[self::FALLBACK_UNIT_SLUG] ?? throw UnitException::notFound($slug);
    }
}
