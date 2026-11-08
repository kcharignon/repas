<?php

namespace Repas\Repas\Application\Command\UpdateServingMeal;


use Closure;
use Repas\Repas\Application\Service\FlattenedRecipeRows;
use Repas\Repas\Application\Service\GroupedMeal;
use Repas\Repas\Application\Service\PartAwareLines;
use Repas\Repas\Application\Service\PlannedMealGroup;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\FlattenedRows;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Service\ConversionService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Change le nombre de personnes d'un repas et recalcule les quantités de sa liste. Quand la recette a été éclatée entre plusieurs listes (ADR 0036
 * décision 9), le changement agit sur TOUT le groupe de repas, en une transaction : chaque liste ajuste exactement les lignes que son repas y a
 * posées (`Meal::places`, figé à la planification). Si une liste du groupe est envoyée au drive, tout est refusé et rien n'est écrit.
 *
 * Les lignes ajustées sont celles de la recette ET celles de ses recettes utilisées (ADR 0040), aplaties par `FlattenedRecipeRows`.
 */
#[AsMessageHandler]
readonly class UpdateServingMealHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private RecipeRepository $recipeRepository,
        private ConversionService $conversionService,
        private PlannedMealGroup $plannedMealGroup,
        private PartAwareLines $partLines,
        private FlattenedRecipeRows $flattenedRows,
    ) {
    }

    /**
     * @throws RecipeException la recette du repas est secrète et n'est pas celle du propriétaire de la liste
     * @throws ShoppingListException une liste du groupe est envoyée au drive, ou la version de la liste principale a changé
     */
    public function __invoke(UpdateServingMealCommand $command): void
    {
        $shoppingList = $this->shoppingListRepository->findOneByMealId($command->mealId);
        $meal = $shoppingList->getMeals()->find(fn(Meal $meal) => $meal->getId() === $command->mealId);

        // Le repas et, s'il fait partie d'un groupe, ceux des autres listes de la planification
        $members = $this->plannedMealGroup->of($shoppingList, $meal);
        $this->plannedMealGroup->assertVersion($members, $command->expectedVersion);
        // Le repas est modifié sans passer par un mutateur de l'agrégat : la garde de la liste envoyée est explicite (ADR 0023), pour tout le groupe
        $this->plannedMealGroup->assertEditable($members);

        // La recette d'abord, même quand rien ne change : un repas hérité qui pointe vers la recette SECRÈTE d'un autre (ADR 0033) est refusé comme
        // une recette inconnue (404), la même réponse quel que soit le nombre de personnes envoyé ; sinon les quantités de la liste diraient son contenu
        $recipe = $this->recipeRepository->findOneById($meal->getRecipeId());
        if (!$recipe->isVisibleTo($shoppingList->getOwnerId())) {
            throw RecipeException::notFound($recipe->getId());
        }

        // Le serving n'a pas ete changer (pour aucun repas du groupe)
        $changing = array_values(array_filter($members, static fn(GroupedMeal $member): bool => $member->meal->getServing() !== $command->serving));
        if ($changing === []) {
            return ;
        }

        // Les recettes utilisées par la recette (ADR 0040), lues en lot seulement si elle en a ; leurs ingrédients s'ajoutent ou se déduisent comme les siens
        $flat = $this->flattenedRows->of($recipe, $shoppingList->getOwnerId());
        $allRows = $flat->allRows();

        // Les quantités de chaque repas, calculées avant la première écriture : une conversion manquante refuse tout
        $this->conversionService->preload($allRows);
        // Ajouter des personnes ajoute des parties au registre (ADR 0038 décision 9) : leurs parents sont lus, en une fois, seulement dans ce cas ;
        // en retirer se décide d'après la liste (`subtractLine`) et ne lit rien de plus
        $parents = [];
        foreach ($changing as $member) {
            if ($command->serving > $member->meal->getServing()) {
                $parents = $this->partLines->parentsOf(array_map(static fn(RecipeRow $row): Ingredient => $row->getIngredient(), $allRows->toArray()));
                break;
            }
        }
        $changes = [];
        foreach ($changing as $member) {
            // Le coefficient de difference = Nouveau Serving - Ancien Serving
            // S'il est positif on dois ajouter, s'il est négatif on doit soustraire
            $coefficient = $command->serving - $member->meal->getServing();

            // On récupere les Ingredients de la recette dans les bonnes proportions, ceux que ce repas a posés dans sa liste
            $flattened = $flat->forServing(abs($coefficient));
            $rows = $flattened->rows()->filter(static fn(RecipeRow $row): bool => $member->meal->places($row->getIngredient()->getSlug()));
            $changes[] = [$member, array_map(
                fn(RecipeRow $row): Closure => $this->lineChange($coefficient > 0, $row, $parents, $flattened),
                array_values($rows->toArray()),
            )];
        }

        foreach ($changes as [$member, $lineChanges]) {
            foreach ($lineChanges as $lineChange) {
                $lineChange($member->shoppingList);
            }

            $member->meal->setServing($command->serving);

            $this->shoppingListRepository->save($member->shoppingList);
        }
    }

    /**
     * La quantité d'une ligne de recette, calculée MAINTENANT dans l'unité d'achat (une conversion manquante refuse tout avant la première écriture),
     * et ce qu'il faudra en faire sur la liste : l'ajouter (une partie, dans la ligne de son parent) ou la déduire (le registre de la liste dit si
     * c'est une partie).
     *
     * @param array<string, Ingredient> $parents
     *
     * @return Closure(ShoppingList): void
     *
     * @throws IngredientException la ligne ne se convertit pas (elle nomme alors la recette utilisée d'où elle vient, ADR 0040 décision 16)
     */
    private function lineChange(bool $increase, RecipeRow $row, array $parents, FlattenedRows $flattened): Closure
    {
        try {
            $quantity = $this->conversionService->convertRecipeRowToPurchaseUnit($row);
            $line = $increase ? $this->partLines->prepare($row->getIngredient(), $quantity, $parents) : null;
        } catch (IngredientException $failure) {
            throw $flattened->attribute($failure, $row);
        }
        if ($line !== null) {
            return static fn(ShoppingList $list) => $line->addTo($list);
        }

        $ingredientSlug = $row->getIngredient()->getSlug();

        return static fn(ShoppingList $list) => $list->subtractLine($ingredientSlug, $quantity);
    }
}
