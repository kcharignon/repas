<?php

namespace Repas\Repas\Application\Command\UpdateServingMeal;


use Repas\Repas\Application\Service\GroupedMeal;
use Repas\Repas\Application\Service\PlannedMealGroup;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Service\ConversionService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Change le nombre de personnes d'un repas et recalcule les quantités de sa liste. Quand la recette a été éclatée entre plusieurs listes (ADR 0036
 * décision 9), le changement agit sur TOUT le groupe de repas, en une transaction : chaque liste ajuste exactement les lignes que son repas y a
 * posées (`Meal::places`, figé à la planification). Si une liste du groupe est envoyée au drive, tout est refusé et rien n'est écrit.
 */
#[AsMessageHandler]
readonly class UpdateServingMealHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private RecipeRepository $recipeRepository,
        private ConversionService $conversionService,
        private PlannedMealGroup $plannedMealGroup,
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

        // Les quantités de chaque repas, calculées avant la première écriture : une conversion manquante refuse tout
        $this->conversionService->preload($recipe->getRows());
        $changes = [];
        foreach ($changing as $member) {
            // Le coefficient de difference = Nouveau Serving - Ancien Serving
            // S'il est positif on dois ajouter, s'il est négatif on doit soustraire
            $coefficient = $command->serving - $member->meal->getServing();

            // On récupere les Ingredients de la recette dans les bonnes proportions, ceux que ce repas a posés dans sa liste
            $rows = $recipe->getRowForServing(abs($coefficient))->filter(static fn(RecipeRow $row): bool => $member->meal->places($row->getIngredient()->getSlug()));
            $changes[] = [$member, $coefficient > 0, array_map(
                // On calcule la quantité dans l'unité d'achat
                fn(RecipeRow $row): array => [$row->getIngredient()->getSlug(), $row->getIngredient()->getDefaultPurchaseUnit()->getSlug(), $this->conversionService->convertRecipeRowToPurchaseUnit($row)],
                array_values($rows->toArray()),
            )];
        }

        foreach ($changes as [$member, $increase, $lines]) {
            foreach ($lines as [$ingredientSlug, $purchaseUnitSlug, $quantity]) {
                if ($increase) {
                    // On ajoute cette quantité dans la liste d'achat
                    $member->shoppingList->addRow($ingredientSlug, $purchaseUnitSlug, $quantity);
                } else {
                    // On déduit cette quantité dans la liste d'achat
                    $member->shoppingList->subtractRow($ingredientSlug, $quantity);
                }
            }

            $member->meal->setServing($command->serving);

            $this->shoppingListRepository->save($member->shoppingList);
        }
    }
}
