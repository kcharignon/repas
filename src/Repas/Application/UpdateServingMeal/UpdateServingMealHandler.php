<?php

namespace Repas\Repas\Application\UpdateServingMeal;


use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Service\ConversionService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateServingMealHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private ConversionService $conversionService,
    ) {
    }

    public function __invoke(UpdateServingMealCommand $command): void
    {
        $shoppingList = $this->shoppingListRepository->findOneByMealId($command->mealId);

        $meal = $shoppingList->getMeals()->find(fn(Meal $meal) => $meal->getId() === $command->mealId);

        // Le serving n'a pas ete changer
        if ($meal->getServing() === $command->serving) {
            return ;
        }

        $recipe = $meal->getRecipe();

        // Le coefficient de difference = Nouveau Serving - Ancien Serving
        // S'il est positif on dois ajouter, s'il est négatif on doit soustraire
        $coefficient = $command->serving - $meal->getServing();

        // On récupere les Ingredients de la recette dans les bonnes proportions
        $rows = $recipe->getRowForServing(abs($coefficient));

        foreach ($rows as $row) {
            // On calcule la quantité dans l'unité d'achat
            $quantity = $this->conversionService->convertRecipeRowToPurchaseUnit($row);
            if ($meal->getServing() < $command->serving) {
                // On ajoute cette quantité dans la liste d'achat
                $shoppingList->addRow($row->getIngredient(), $quantity);
            } else {
                // On déduit cette quantité dans la liste d'achat
                $shoppingList->subtractRow($row->getIngredient(), $quantity);
            }
        }

        $meal->setServing($command->serving);

        $this->shoppingListRepository->save($shoppingList);
    }
}
