<?php

namespace Repas\Repas\Application\Command\RemoveMealFromPlan;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Service\ConversionService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class removeMealFromPlanHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private RecipeRepository $recipeRepository,
        private ConversionService $conversionService,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws IngredientException
     * @throws RecipeException la recette est inconnue, ou secrète et d'un autre
     */
    public function __invoke(removeMealFromPlanCommand $command): void
    {
        $activeShoppingList = $this->shoppingListRepository->findOneActivateByOwner($command->ownerId);

        if (!$activeShoppingList instanceof ShoppingList) {
            throw ShoppingListException::activeShoppingListNotFound();
        }
        $activeShoppingList->assertVersion($command->expectedVersion);

        $recipe = $this->recipeRepository->findOneById($command->recipeId);
        // La recette secrète d'un autre (ADR 0033) est introuvable pour le propriétaire de la liste : retirer le repas hérité qui y pointe ferait
        // déduire ses quantités, donc en dirait le contenu. Même exception qu'une recette inconnue, avant la lecture des repas
        if (!$recipe->isVisibleTo($command->ownerId)) {
            throw RecipeException::notFound($command->recipeId);
        }
        $meal = $activeShoppingList->getMeals()->find(fn(Meal $meal) => $meal->hasRecipe($recipe));

        // Si meal n'est pas trouvé le repas est déjà supprimé de la liste
        if (!$meal instanceof Meal) {
            return;
        }

        // On récupere les Ingredients de la recette dans les bonnes proportions
        $rows = $recipe->getRowForServing($meal->getServing());

        foreach ($rows as $row) {
            // On calcule la quantité dans l'unité d'achat
            $quantity = $this->conversionService->convertRecipeRowToPurchaseUnit($row);
            // On enleve cette quantité dans la liste d'achat
            $activeShoppingList->subtractRow($row->getIngredient()->getSlug(), $quantity);
        }

        $activeShoppingList->removeMeal($recipe);

        $this->shoppingListRepository->save($activeShoppingList);
    }
}
