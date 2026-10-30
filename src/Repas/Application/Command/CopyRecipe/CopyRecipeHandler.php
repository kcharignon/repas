<?php

namespace Repas\Repas\Application\Command\CopyRecipe;

use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientMatchCandidates;
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
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Copie une recette chez un utilisateur (ADR 0028 décision 8, `docs/ingredients/rapprochement.md`). Pour chaque ingrédient de l'original
 * (traité UNE fois, même s'il est sur plusieurs lignes), le copieur utilisera, dans cet ordre :
 *
 * 1. celui qui est déjà à lui (il copie sa propre recette) ;
 * 2. la PROVENANCE, sans comparer de noms : l'origine de l'ingrédient (`adopted_from`, sinon son slug) et ce que le copieur en a fait, par
 *    ses alias (un compte amorcé a un alias par ingrédient du catalogue qu'il a reçu ; une fusion en écrit un aussi) ;
 * 3. à défaut, un ingrédient du copieur, du MÊME rayon, de même nom (`IngredientNameMatcher`, seuil 0,97, garde-fous) ;
 * 4. sinon une COPIE de l'ingrédient pour le copieur (ingrédient, unités, rayon, image, conversions propres ; les conversions communes
 *    restent partagées) : `IngredientSlugPolicy` lui trouve un slug libre, JAMAIS celui d'un ingrédient existant.
 *
 * Un ingrédient retenu en 2 ou 3 doit pouvoir convertir l'unité de chaque ligne dans son unité d'achat (`ConversionService`), sinon la
 * planification de la recette échouerait : il n'y a alors pas de lien et la copie est faite. Au moindre doute, on crée une copie : un
 * doublon se voit et se fusionne, un faux rapprochement ne se voit pas.
 *
 * Il n'y a plus d'ingrédient commun (amendement du 2026-10-20) : aucune branche « commun », aucune adoption à la volée. Ce cas d'usage ne
 * fusionne jamais deux ingrédients du copieur, n'en modifie aucun et n'écrit aucun alias. TOUTE copie retient l'origine de l'ingrédient
 * copié (D7) : elle n'est donc jamais une création, et l'événement des statistiques ne porte aucun ingrédient.
 */
#[AsMessageHandler]
readonly class CopyRecipeHandler
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
        private UuidGenerator $uuidGenerator,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @throws RecipeException la recette est inconnue ou secrète et d'un autre (pour lui elle n'existe pas : ADR 0033, administrateur compris),
     *                         ou le copieur a déjà `Recipe::MAX_PER_AUTHOR_WITH_COPIES` recettes
     */
    public function __invoke(CopyRecipeCommand $command): void
    {
        // Le plafond du compte d'abord (copies comprises, plus large que celui de l'écriture) : un compte qui l'a atteint ne coûte rien de plus
        if ($this->recipeRepository->authorHasAtLeast($command->authorId, Recipe::MAX_PER_AUTHOR_WITH_COPIES)) {
            throw RecipeException::limitReached();
        }

        $originalRecipe = $this->recipeRepository->findOneById($command->recipeId);
        $copierId = $command->authorId;
        // Avant toute écriture, y compris la copie d'un ingrédient : la recette secrète d'un autre est introuvable, comme une recette inconnue
        if (!$originalRecipe->isVisibleTo($copierId)) {
            throw RecipeException::notFound($command->recipeId);
        }

        // Les ingrédients de l'original et les unités qui y sont utilisées, une fois par ingrédient
        /** @var array<string, Ingredient> $sources */
        $sources = [];
        /** @var array<string, array<string, Unit>> $unitsUsed */
        $unitsUsed = [];
        /** @var RecipeRow $row */
        foreach ($originalRecipe->getRows() as $row) {
            $slug = $row->getIngredient()->getSlug();
            $sources[$slug] ??= $row->getIngredient();
            $unitsUsed[$slug][$row->getUnit()->getSlug()] = $row->getUnit();
        }

        // 1 à 3 : ce que le copieur utilisera sans rien créer, par slug de l'ingrédient d'origine
        /** @var array<string, Ingredient> $retained */
        $retained = $this->retainWithoutCreating($copierId, $sources, $unitsUsed);

        // 4 : une copie pour chacun des autres, dans l'ordre de la recette ; une copie retient son origine : ce n'est pas une création
        foreach ($sources as $slug => $source) {
            if (!isset($retained[$slug])) {
                $retained[$slug] = $this->copyFor($copierId, $source);
            }
        }

        $recipe = Recipe::copyFromOriginal(
            $command->newRecipeId,
            $originalRecipe,
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
        /** @var array<string, string> $proposed le slug de l'ingrédient du copieur proposé par slug d'origine, avant le contrôle des unités */
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
        $byOrigin = $this->slugResolver->resolve($copierId, array_values(array_unique($origins)));
        foreach ($origins as $slug => $origin) {
            // Sans alias, la provenance ne dit rien : on compare les noms
            if (isset($byOrigin[$origin])) {
                $proposed[$slug] = $byOrigin[$origin];
            }
        }

        // 3. Le nom : ce que le copieur a déjà, du même rayon
        $candidates = null;
        foreach ($sources as $slug => $source) {
            if (isset($retained[$slug]) || isset($proposed[$slug])) {
                continue;
            }
            $candidates ??= $this->matchCandidates->visibleTo($copierId);
            $match = $this->nameMatcher->match($source->getName(), $source->getDepartment()->getSlug(), $candidates);
            if ($match !== null) {
                $proposed[$slug] = $match->candidate->slug;
            }
        }

        // 5. L'unité de chaque ligne doit se convertir dans l'unité d'achat de l'ingrédient proposé : sinon la recette copiée ne se
        // planifierait pas
        $models = $this->ingredientsBySlug(array_values($proposed));
        foreach ($proposed as $slug => $targetSlug) {
            if (!$this->canConvertAll($models[$targetSlug], $unitsUsed[$slug])) {
                unset($proposed[$slug]);
            }
        }

        foreach ($proposed as $slug => $targetSlug) {
            $retained[$slug] = $models[$targetSlug];
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

    private function copyFor(string $copierId, Ingredient $source): Ingredient
    {
        $copy = Ingredient::copyFromOriginal(
            $source,
            $copierId,
            $this->slugPolicy->forAdoption($source->getName(), $copierId, fn(string $slug): bool => $this->ingredientRepository->exists($slug)),
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
}
