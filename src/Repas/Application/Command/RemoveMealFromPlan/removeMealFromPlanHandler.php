<?php

namespace Repas\Repas\Application\Command\RemoveMealFromPlan;


use Repas\Repas\Application\Service\FlattenedRecipeRows;
use Repas\Repas\Application\Service\PlannedMealGroup;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Service\ConversionService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Retire une recette du plan et déduit ses ingrédients. Le repas est cherché dans la liste principale d'abord (comme avant les types), puis dans les
 * autres listes en cours (une recette dont aucun produit n'est allé dans la liste principale). S'il fait partie d'un groupe (ADR 0036 décision 9), le
 * retrait agit sur TOUT le groupe, en une transaction : chaque liste ne perd que les lignes que son repas y avait posées (`Meal::places`, figé à la
 * planification). Si une liste du groupe est envoyée au drive, tout est refusé et rien n'est écrit.
 *
 * Les lignes déduites sont celles de la recette ET celles de ses recettes utilisées (ADR 0040), aplaties par `FlattenedRecipeRows` d'après les recettes
 * d'AUJOURD'HUI : le repas ne mémorise ni lignes ni composants (limite connue, ADR 0040 décision 14).
 */
#[AsMessageHandler]
readonly class removeMealFromPlanHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private RecipeRepository $recipeRepository,
        private ConversionService $conversionService,
        private PlannedMealGroup $plannedMealGroup,
        private FlattenedRecipeRows $flattenedRows,
    ) {
    }

    /**
     * @throws ShoppingListException aucune liste en cours, une liste du groupe est envoyée au drive, ou la version de la liste principale a changé
     * @throws IngredientException
     * @throws RecipeException la recette est inconnue, ou secrète et d'un autre
     */
    public function __invoke(removeMealFromPlanCommand $command): void
    {
        $activeShoppingList = $this->shoppingListRepository->findOneActivateByOwner($command->ownerId);

        $recipe = $this->recipeRepository->findOneById($command->recipeId);
        // La recette secrète d'un autre (ADR 0033) est introuvable pour le propriétaire de la liste : retirer le repas hérité qui y pointe ferait
        // déduire ses quantités, donc en dirait le contenu. Même exception qu'une recette inconnue, avant la lecture des repas
        if (!$recipe->isVisibleTo($command->ownerId)) {
            throw RecipeException::notFound($command->recipeId);
        }

        $meal = $activeShoppingList?->mealOf($recipe);
        $shoppingList = $activeShoppingList;
        if (!$meal instanceof Meal) {
            // Aucun produit de la recette n'est allé dans la liste principale : son repas est dans la liste en cours d'un type
            $shoppingList = $this->shoppingListRepository->findOneActiveWithRecipe($command->ownerId, $recipe->getId());
            $meal = $shoppingList?->mealOf($recipe);
        }

        if (!$meal instanceof Meal) {
            // Si aucune liste active on ne peut pas retirer de repas
            if (!$activeShoppingList instanceof ShoppingList) {
                throw ShoppingListException::activeShoppingListNotFound();
            }

            // Si meal n'est pas trouvé le repas est déjà supprimé de la liste
            return;
        }

        $members = $this->plannedMealGroup->of($shoppingList, $meal);
        $this->plannedMealGroup->assertVersion($members, $command->expectedVersion);
        $this->plannedMealGroup->assertEditable($members);

        // Les recettes utilisées par la recette (ADR 0040), lues en lot seulement si elle en a ; leurs ingrédients se déduisent comme les siens
        $flat = $this->flattenedRows->of($recipe, $command->ownerId);

        // Les quantités à déduire de chaque liste, calculées avant la première écriture : une conversion manquante refuse tout
        $this->conversionService->preload($flat->allRows());
        $deductions = [];
        foreach ($members as $member) {
            // On récupere les Ingredients de la recette dans les bonnes proportions, ceux que ce repas avait posés dans sa liste
            $flattened = $flat->forServing($member->meal->getServing());
            $rows = $flattened->rows()->filter(static fn(RecipeRow $row): bool => $member->meal->places($row->getIngredient()->getSlug()));
            $deductions[] = array_map(
                // On calcule la quantité dans l'unité d'achat (une ligne d'une recette utilisée qui ne se convertit pas nomme cette recette, ADR 0040 décision 16)
                function (RecipeRow $row) use ($flattened): array {
                    try {
                        return [$row->getIngredient()->getSlug(), $this->conversionService->convertRecipeRowToPurchaseUnit($row)];
                    } catch (IngredientException $failure) {
                        throw $flattened->attribute($failure, $row);
                    }
                },
                array_values($rows->toArray()),
            );
        }

        foreach ($members as $index => $member) {
            foreach ($deductions[$index] as [$ingredientSlug, $quantity]) {
                // On enleve cette quantité dans la liste d'achat (le registre de la liste dit si c'est une partie, ADR 0038 décision 9)
                $member->shoppingList->subtractLine($ingredientSlug, $quantity);
            }

            $member->shoppingList->removeMeal($recipe);

            $this->shoppingListRepository->save($member->shoppingList);
        }
    }
}
