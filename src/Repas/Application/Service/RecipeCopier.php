<?php

namespace Repas\Repas\Application\Service;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientMatchCandidates;
use Repas\Repas\Domain\Interface\IngredientPartLinkRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Service\IngredientNameMatcher;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Shared\Domain\Event\RecipesOrIngredientsCreatedEvent;
use Repas\Shared\Domain\Interface\UuidGenerator;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * LA copie d'une recette chez un utilisateur et le rapprochement de ses ingrédients (ADR 0028 décision 8, `docs/ingredients/rapprochement.md`),
 * partagés par `CopyRecipeHandler` (copier la recette d'un autre) et `ImportMarmitonRecipeHandler` (copier la recette Marmiton neutre, ADR 0037) :
 * UN SEUL point d'entrée, `copy()`, pour que tout ce qui doit suivre une copie d'ingrédients (la liaison des sous-ingrédients, ADR 0038
 * décision 20) s'ajoute à un seul endroit. Pour chaque ingrédient de l'original (traité UNE fois, même s'il est sur plusieurs lignes), le
 * copieur utilisera, dans cet ordre :
 *
 * 1. celui qui est déjà à lui (il copie sa propre recette) ;
 * 2. la PROVENANCE, sans comparer de noms : l'origine de l'ingrédient (`adopted_from`, sinon son slug) et ce que le copieur en a fait, par
 *    ses alias (un compte amorcé a un alias par ingrédient du catalogue qu'il a reçu ; une fusion en écrit un aussi) ;
 * 3. à défaut, un ingrédient du copieur, du MÊME rayon, de même nom (`IngredientNameMatcher`, seuil 0,97, garde-fous) ;
 * 4. sinon une COPIE de l'ingrédient pour le copieur (ingrédient, unités, rayon, image, conversions propres ; les conversions communes
 *    restent partagées) : `IngredientSlugPolicy` lui trouve un slug libre, JAMAIS celui d'un ingrédient existant.
 * 5. après les copies, la LIAISON des sous-ingrédients (ADR 0038 décision 20) : une partie copiée est reliée au parent que le copieur a déjà,
 *    par provenance (`IngredientPartLinkRepository::linkCopies`) ; jamais un parent copié « pour compléter », jamais un slug étranger.
 *
 * Un ingrédient retenu en 2 ou 3 doit pouvoir convertir l'unité de chaque ligne dans son unité d'achat (`ConversionService`), sinon la
 * planification de la recette échouerait : il n'y a alors pas de lien et la copie est faite. Au moindre doute, on crée une copie : un
 * doublon se voit et se fusionne, un faux rapprochement ne se voit pas.
 *
 * Il n'y a plus d'ingrédient commun (amendement du 2026-10-20) : aucune branche « commun », aucune adoption à la volée. Cette copie ne
 * fusionne jamais deux ingrédients du copieur, n'en modifie aucun et n'écrit aucun alias. TOUTE copie retient l'origine de l'ingrédient
 * copié (D7) : elle n'est donc jamais une création, et l'événement des statistiques ne porte aucun ingrédient.
 */
readonly class RecipeCopier
{
    public function __construct(
        private RecipeRepository $recipeRepository,
        private IngredientRepository $ingredientRepository,
        private ConversionRepository $conversionRepository,
        private IngredientSlugResolver $slugResolver,
        private IngredientMatchCandidates $matchCandidates,
        private IngredientNameMatcher $nameMatcher,
        private IngredientSlugPolicy $slugPolicy,
        private ConversionService $conversionService,
        private IngredientPartLinkRepository $partLinks,
        private UuidGenerator $uuidGenerator,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Le plafond du compte (copies comprises, plus large que celui de l'écriture) : un compte qui l'a atteint ne copie plus rien. À poser AVANT
     * toute lecture coûteuse ou tout appel sortant, pour qu'un compte au plafond ne coûte rien de plus.
     *
     * @throws RecipeException le copieur a déjà `Recipe::MAX_PER_AUTHOR_WITH_COPIES` recettes
     */
    public function assertCanCopy(string $copierId): void
    {
        if ($this->recipeRepository->authorHasAtLeast($copierId, Recipe::MAX_PER_AUTHOR_WITH_COPIES)) {
            throw RecipeException::limitReached();
        }
    }

    /**
     * Copie `$original` chez `$copierId` sous l'identifiant `$newRecipeId`, l'enregistre et annonce la copie aux statistiques du copieur. Le
     * plafond du compte est jugé avant (`assertCanCopy`), par l'appelant.
     *
     * @throws RecipeException l'original est secret et d'un autre (pour le copieur elle n'existe pas : ADR 0033, administrateur compris)
     */
    public function copy(Recipe $original, string $copierId, string $newRecipeId): Recipe
    {
        // Avant toute écriture, y compris la copie d'un ingrédient : la recette secrète d'un autre est introuvable, comme une recette inconnue
        if (!$original->isVisibleTo($copierId)) {
            throw RecipeException::notFound($original->getId());
        }

        // 1 à 4 : l'ingrédient que le copieur utilisera pour chacun de ceux de l'original
        [$sources, $unitsUsed] = $this->ingredientsAndUnitsOf($original);
        [$retained, $copiedAPart] = $this->retainOrCopy($copierId, $sources, $unitsUsed);

        // 5 : le découpage de l'auteur voyage avec les copies (ADR 0038 décisions 5 et 20). Une copie ne recopie jamais `parent_slug` : la partie
        // copiée est reliée au parent que le copieur a DÉJÀ (ou qu'il vient de recevoir), par provenance, sans jamais écraser un lien à lui. Une
        // seule instruction, seulement quand une copie vient d'une partie de l'auteur : sans découpage dans la recette, la copie ne coûte
        // aucune requête de plus.
        if ($copiedAPart) {
            $this->partLinks->linkCopies($copierId);
        }

        $recipe = Recipe::copyFromOriginal(
            $newRecipeId,
            $original,
            $copierId,
            $retained,
            fn(): string => $this->uuidGenerator->generate(),
        );
        // La copie reprend le nom de l'original : la règle de la première lettre en majuscule s'applique aussi à lui (ADR 0030), sans effet
        // sur un nom déjà bon
        $recipe->setName(StringTool::capitalizeFirst($recipe->getName()));
        $this->recipeRepository->save($recipe);

        $this->eventDispatcher->dispatch(new RecipesOrIngredientsCreatedEvent(
            userId: $copierId,
            ingredientSlugs: Tab::newEmptyTyped('string'),
            recipeIds: Tab::fromArray($recipe->getId())
        ));

        return $recipe;
    }

    /**
     * Les ingrédients que le propriétaire des recettes Marmiton (`Recipe::MARMITON_OWNER_ID`) utilisera à la place de ceux d'un administrateur,
     * quand celui-ci modifie une recette sans propriétaire (ADR 0037, amendement du 2026-11-08, MAR-005) : les étapes 1 à 4 de la copie
     * ordinaire, avec le propriétaire réservé pour copieur. Un ingrédient réservé de même origine (ou, à défaut, de même nom et de même rayon)
     * est réutilisé tant qu'il convertit les unités des lignes, sinon l'ingrédient de l'administrateur lui est copié (conversions propres,
     * origine retenue) : d'une modification à l'autre, un même ingrédient n'est donc jamais copié deux fois.
     *
     * Ce n'est PAS une copie de recette : rien n'est écrit pour la recette, aucun événement n'est annoncé (le propriétaire réservé n'est pas
     * un compte), et les sous-ingrédients ne sont pas liés : les ingrédients d'une neutre n'ont jamais de parent (ADR 0038 décision 5, comme à
     * l'import). Un ingrédient copié est écrit dans la transaction de l'appelant.
     *
     * @param array<string, Ingredient> $sources les ingrédients de l'administrateur, par slug
     * @param array<string, array<string, Unit>> $unitsUsed les unités des lignes, par slug d'ingrédient
     * @return array<string, Ingredient> l'ingrédient du propriétaire réservé par slug d'ingrédient de l'administrateur
     */
    public function retainIngredientsForMarmitonOwner(array $sources, array $unitsUsed): array
    {
        return $this->retainOrCopy(Recipe::MARMITON_OWNER_ID, $sources, $unitsUsed)[0];
    }

    /**
     * Les ingrédients d'une recette et les unités qui y sont utilisées, une fois par ingrédient.
     *
     * @return array{0: array<string, Ingredient>, 1: array<string, array<string, Unit>>} les ingrédients par slug, les unités par slug d'ingrédient
     */
    private function ingredientsAndUnitsOf(Recipe $recipe): array
    {
        /** @var array<string, Ingredient> $sources */
        $sources = [];
        /** @var array<string, array<string, Unit>> $unitsUsed */
        $unitsUsed = [];
        /** @var RecipeRow $row */
        foreach ($recipe->getRows() as $row) {
            $slug = $row->getIngredient()->getSlug();
            $sources[$slug] ??= $row->getIngredient();
            $unitsUsed[$slug][$row->getUnit()->getSlug()] = $row->getUnit();
        }

        return [$sources, $unitsUsed];
    }

    /**
     * Les étapes 1 à 4 : pour chaque ingrédient de `$sources` (traité une fois), celui que `$copierId` utilisera, retenu sans rien créer
     * (`retainWithoutCreating`) ou copié pour lui, dans l'ordre des sources. Une copie retient son origine : ce n'est pas une création.
     *
     * @param array<string, Ingredient> $sources les ingrédients de l'original, par slug
     * @param array<string, array<string, Unit>> $unitsUsed les unités des lignes de l'original, par slug d'ingrédient
     * @return array{0: array<string, Ingredient>, 1: bool} l'ingrédient du copieur par slug d'ingrédient de l'original, et si une copie vient
     *         d'une partie (un sous-ingrédient) de l'auteur
     */
    private function retainOrCopy(string $copierId, array $sources, array $unitsUsed): array
    {
        // 1 à 3 : ce que le copieur utilisera sans rien créer, par slug de l'ingrédient d'origine
        /** @var array<string, Ingredient> $retained */
        $retained = $this->retainWithoutCreating($copierId, $sources, $unitsUsed);

        // 4 : une copie pour chacun des autres, dans l'ordre de la recette ; une copie retient son origine : ce n'est pas une création
        $copiedAPart = false;
        foreach ($sources as $slug => $source) {
            if (!isset($retained[$slug])) {
                $retained[$slug] = $this->copyIngredient($copierId, $source);
                $copiedAPart = $copiedAPart || $source->isPart();
            }
        }

        return [$retained, $copiedAPart];
    }

    /**
     * La copie d'UN ingrédient chez `$ownerId` (ingrédient, unités, rayon, image et conversions propres ; les conversions communes restent
     * partagées), sous le premier slug libre : jamais celui d'un ingrédient existant. Elle retient l'origine de l'ingrédient copié
     * (`adopted_from`) : ce n'est pas une création. Sert aussi à copier l'ingrédient « de base » du catalogue chez le propriétaire des recettes
     * Marmiton (ADR 0037 décision 6).
     */
    public function copyIngredient(string $ownerId, Ingredient $source): Ingredient
    {
        $copy = Ingredient::copyFromOriginal(
            $source,
            $ownerId,
            $this->slugPolicy->forAdoption($source->getName(), $ownerId, fn(string $slug): bool => $this->ingredientRepository->exists($slug)),
        );
        // Idem pour le nom de l'ingrédient copié (ADR 0030) ; le slug et le nom normalisé ne changent pas
        $copy->setName(StringTool::capitalizeFirst($copy->getName()));
        $this->ingredientRepository->save($copy);
        // Les conversions PROPRES de l'original (les conversions communes restent partagées)
        foreach ($this->conversionRepository->findByIngredient($source) as $conversion) {
            $this->conversionRepository->save(Conversion::copyFromOriginal($this->uuidGenerator->generate(), $conversion, $copy));
        }

        return $copy;
    }

    /**
     * Les étapes 1 à 3 : ce que le copieur utilisera sans qu'il faille rien créer.
     *
     * @param array<string, Ingredient> $sources les ingrédients de l'original, par slug
     * @param array<string, array<string, Unit>> $unitsUsed les unités des lignes de l'original, par slug d'ingrédient
     * @return array<string, Ingredient> l'ingrédient du copieur par slug d'origine ; un ingrédient sans lien est absent
     */
    private function retainWithoutCreating(string $copierId, array $sources, array $unitsUsed): array
    {
        /** @var array<string, Ingredient> $retained */
        $retained = [];
        /** @var array<string, list<string>> $proposed les slugs de l'ingrédient du copieur proposés par slug d'origine (le préféré d'abord), avant le contrôle des unités */
        $proposed = [];

        // 1. Ce qui est déjà au copieur
        foreach ($sources as $slug => $source) {
            if ($source->getCreatorId() === $copierId) {
                $retained[$slug] = $source;
            }
        }

        // 2. La provenance : l'origine de l'ingrédient (l'ingrédient dont il est la copie, sinon lui-même) et ce que le copieur en a fait
        $origins = [];
        foreach ($sources as $slug => $source) {
            if (!isset($retained[$slug])) {
                $origins[$slug] = $source->getAdoptedFrom() ?? $slug;
            }
        }
        $candidates = null;
        if ($copierId === Recipe::MARMITON_OWNER_ID) {
            // Le propriétaire réservé des recettes Marmiton n'est pas un compte : aucun amorçage ne lui écrit d'alias. Ce qu'il a fait d'une
            // origine se lit dans l'origine de ses propres ingrédients (`adopted_from`), du plus petit slug au plus grand ; l'ingrédient
            // retenu est le premier qui convertit les unités des lignes (voir 5), de sorte qu'une mise à jour qui en a copié un meilleur
            // le retrouve la fois suivante, sans en copier un troisième.
            $candidates = $this->matchCandidates->visibleTo($copierId);
            $copiesOf = [];
            foreach ($candidates as $candidate) {
                if ($candidate->adoptedFrom !== null) {
                    $copiesOf[$candidate->adoptedFrom][] = $candidate->slug;
                }
            }
            foreach ($origins as $slug => $origin) {
                if (isset($copiesOf[$origin])) {
                    $proposed[$slug] = $copiesOf[$origin];
                }
            }
        } else {
            $byOrigin = $this->slugResolver->resolve($copierId, array_values(array_unique($origins)));
            foreach ($origins as $slug => $origin) {
                // Sans alias, la provenance ne dit rien : on compare les noms
                if (isset($byOrigin[$origin])) {
                    $proposed[$slug] = [$byOrigin[$origin]];
                }
            }
        }

        // 3. Le nom : ce que le copieur a déjà, du même rayon
        foreach ($sources as $slug => $source) {
            if (isset($retained[$slug]) || isset($proposed[$slug])) {
                continue;
            }
            $candidates ??= $this->matchCandidates->visibleTo($copierId);
            $match = $this->nameMatcher->match($source->getName(), $source->getDepartment()->getSlug(), $candidates);
            if ($match !== null) {
                $proposed[$slug] = [$match->candidate->slug];
            }
        }

        // 5. L'unité de chaque ligne doit se convertir dans l'unité d'achat de l'ingrédient proposé : sinon la recette copiée ne se
        // planifierait pas
        $toLoad = [];
        foreach ($proposed as $targetSlugs) {
            array_push($toLoad, ...$targetSlugs);
        }
        $models = $this->ingredientsBySlug($toLoad);
        foreach ($proposed as $slug => $targetSlugs) {
            foreach ($targetSlugs as $targetSlug) {
                if ($this->canConvertAll($models[$targetSlug], $unitsUsed[$slug])) {
                    $retained[$slug] = $models[$targetSlug];
                    break;
                }
            }
        }

        return $retained;
    }

    /**
     * @param array<array-key, string> $slugs
     * @return array<string, Ingredient> par slug
     */
    private function ingredientsBySlug(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $ingredients = [];
        foreach ($this->ingredientRepository->findBySlugs(new Tab(array_values(array_unique($slugs)), 'string')) as $ingredient) {
            $ingredients[$ingredient->getSlug()] = $ingredient;
        }

        return $ingredients;
    }

    /**
     * @param array<string, Unit> $units
     */
    private function canConvertAll(Ingredient $ingredient, array $units): bool
    {
        foreach ($units as $unit) {
            try {
                $this->conversionService->assertConvertibleToPurchaseUnit($ingredient, $unit);
            } catch (IngredientException) {
                return false;
            }
        }

        return true;
    }
}
