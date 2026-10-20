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
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Copie une recette chez un utilisateur (ADR 0028 décision 8, `docs/ingredients/rapprochement.md`). Pour chaque ingrédient de l'original
 * (traité UNE fois, même s'il est sur plusieurs lignes), le copieur utilisera, dans cet ordre :
 *
 * 1. celui qui est déjà à lui (il copie sa propre recette) ;
 * 2. la PROVENANCE, sans comparer de noms : si l'ingrédient est un commun ou l'adoption d'un commun `C`, ce que le copieur a fait de `C` -
 *    son alias, sinon l'adoption à la volée de `C` s'il est adoptant, sinon `C` lui-même (`IngredientSlugResolver`) ;
 * 3. à défaut, un ingrédient que le copieur voit DÉJÀ, du MÊME rayon, de même nom (`IngredientNameMatcher`, seuil 0,97, garde-fous) ;
 * 4. sinon une COPIE de l'ingrédient pour le copieur (ingrédient, unités, rayon, image, conversions propres ; les conversions communes
 *    restent partagées) : `IngredientSlugPolicy` lui trouve un slug libre, JAMAIS celui d'un ingrédient existant.
 *
 * Un ingrédient retenu en 2 ou 3 doit pouvoir convertir l'unité de chaque ligne dans son unité d'achat (`ConversionService`), sinon la
 * planification de la recette échouerait : il n'y a alors pas de lien et la copie est faite. Au moindre doute, on crée une copie : un
 * doublon se voit et se fusionne, un faux rapprochement ne se voit pas.
 *
 * Ce cas d'usage ne fusionne jamais deux ingrédients du copieur, n'en modifie aucun et n'écrit aucun alias (un alias d'adoption ferait
 * du copieur un « adoptant »). Seuls les ingrédients réellement CRÉÉS sont annoncés aux statistiques : une copie qui retient l'origine
 * d'une adoption n'est pas une création (décision 7).
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
     * @throws RecipeException la recette est inconnue
     */
    public function __invoke(CopyRecipeCommand $command): void
    {
        $originalRecipe = $this->recipeRepository->findOneById($command->recipeId);
        $copierId = $command->authorId;

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

        // 4 : une copie pour chacun des autres, dans l'ordre de la recette
        $created = Tab::newEmptyTyped('string');
        foreach ($sources as $slug => $source) {
            if (isset($retained[$slug])) {
                continue;
            }
            $copy = $this->copyFor($copierId, $source);
            $retained[$slug] = $copy;
            if (!$copy->isAdoption()) {
                $created[] = $copy->getSlug();
            }
        }

        $recipe = Recipe::copyFromOriginal(
            $command->newRecipeId,
            $originalRecipe,
            $copierId,
            $retained,
            fn(): string => $this->uuidGenerator->generate(),
        );
        $this->recipeRepository->save($recipe);

        $this->eventDispatcher->dispatch(new RecipesOrIngredientsCreatedEvent(
            userId: $copierId,
            ingredientSlugs: $created,
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

        // 2. La provenance : le commun dont l'ingrédient vient (ou qu'il est), et ce que le copieur en a fait
        $origins = [];
        $knownCommons = [];
        foreach ($sources as $slug => $source) {
            if ($source->getCreatorId() === null) {
                $knownCommons[$slug] = true;
            }
            if (!isset($retained[$slug]) && ($origin = $source->getAdoptedFrom() ?? ($source->getCreatorId() === null ? $slug : null)) !== null) {
                $origins[$slug] = $origin;
            }
        }
        $byOrigin = $this->slugResolver->resolveForUser($copierId, array_values(array_unique($origins)));
        // Un commun sans alias et que le copieur n'adopte pas (il n'est pas adoptant) reste le commun lui-même, tant qu'il existe
        $toCheck = array_diff(array_unique($origins), array_keys($byOrigin), array_keys($knownCommons));
        $stillCommon = array_flip($this->ingredientRepository->findCommonSlugs(array_values($toCheck)));
        foreach ($origins as $slug => $origin) {
            $found = $byOrigin[$origin] ?? (isset($knownCommons[$origin]) || isset($stillCommon[$origin]) ? $origin : null);
            if ($found !== null) {
                $proposed[$slug] = $found;
            }
            // Un commun qui a disparu : la provenance ne dit plus rien, on compare les noms
        }

        // 3. Le nom : ce que le copieur voit déjà, du même rayon
        $candidates = null;
        $namedCommons = [];
        foreach ($sources as $slug => $source) {
            if (isset($retained[$slug]) || isset($proposed[$slug])) {
                continue;
            }
            $candidates ??= $this->matchCandidates->visibleTo($copierId);
            $match = $this->nameMatcher->match($source->getName(), $source->getDepartment()->getSlug(), $candidates);
            if ($match !== null) {
                $proposed[$slug] = $match->candidate->slug;
                if ($match->candidate->isCommon()) {
                    $namedCommons[$slug] = true;
                }
            }
        }

        // 5. L'unité de chaque ligne doit se convertir dans l'unité d'achat de l'ingrédient proposé : sinon la recette copiée ne se
        // planifierait pas. Un ingrédient qui se propose lui-même (un commun) sert déjà les lignes de l'original.
        $models = $sources + $this->ingredientsBySlug(array_diff($proposed, array_keys($sources)));
        foreach ($proposed as $slug => $targetSlug) {
            if ($targetSlug !== $slug && !$this->canConvertAll($models[$targetSlug], $unitsUsed[$slug])) {
                unset($proposed[$slug], $namedCommons[$slug]);
            }
        }

        // Un commun retenu par son nom, que le copieur n'a pas adopté : un adoptant l'adopte à la volée avant de s'en servir
        if ($namedCommons !== []) {
            $adopted = $this->slugResolver->resolveForUser($copierId, array_values(array_unique(array_map(static fn(string $slug): string => $proposed[$slug], array_keys($namedCommons)))));
            foreach (array_keys($namedCommons) as $slug) {
                $proposed[$slug] = $adopted[$proposed[$slug]] ?? $proposed[$slug];
            }
            $models += $this->ingredientsBySlug(array_diff($proposed, array_keys($models)));
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
        $this->ingredientRepository->save($copy);
        // Les conversions PROPRES de l'original (les conversions communes restent partagées)
        foreach ($this->conversionRepository->findByIngredient($source) as $conversion) {
            $this->conversionRepository->save(Conversion::copyFromOriginal($this->uuidGenerator->generate(), $conversion, $copy));
        }

        return $copy;
    }
}
