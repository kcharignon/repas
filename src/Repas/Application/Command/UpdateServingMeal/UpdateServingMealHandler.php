<?php

namespace Repas\Repas\Application\Command\UpdateServingMeal;


use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Service\ConversionService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateServingMealHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private RecipeRepository $recipeRepository,
        private ConversionService $conversionService,
    ) {
    }

    /**
     * @throws RecipeException la recette du repas est secrète et n'est pas celle du propriétaire de la liste
     */
    public function __invoke(UpdateServingMealCommand $command): void
    {
        $shoppingList = $this->shoppingListRepository->findOneByMealId($command->mealId);
        $shoppingList->assertVersion($command->expectedVersion);
        // Le repas est modifié sans passer par un mutateur de l'agrégat : la garde de la liste envoyée est explicite (ADR 0023)
        $shoppingList->assertEditable();

        $meal = $shoppingList->getMeals()->find(fn(Meal $meal) => $meal->getId() === $command->mealId);

        // La recette d'abord, même quand rien ne change : un repas hérité qui pointe vers la recette SECRÈTE d'un autre (ADR 0033) est refusé comme
        // une recette inconnue (404), la même réponse quel que soit le nombre de personnes envoyé ; sinon les quantités de la liste diraient son contenu
        $recipe = $this->recipeRepository->findOneById($meal->getRecipeId());
        if (!$recipe->isVisibleTo($shoppingList->getOwnerId())) {
            throw RecipeException::notFound($recipe->getId());
        }

        // Le serving n'a pas ete changer
        if ($meal->getServing() === $command->serving) {
            return ;
        }

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
                $shoppingList->addRow($row->getIngredient()->getSlug(), $row->getIngredient()->getDefaultPurchaseUnit()->getSlug(), $quantity);
            } else {
                // On déduit cette quantité dans la liste d'achat
                $shoppingList->subtractRow($row->getIngredient()->getSlug(), $quantity);
            }
        }

        $meal->setServing($command->serving);

        $this->shoppingListRepository->save($shoppingList);
    }
}
