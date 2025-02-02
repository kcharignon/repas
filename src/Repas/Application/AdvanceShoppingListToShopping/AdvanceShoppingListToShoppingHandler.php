<?php

namespace Repas\Repas\Application\AdvanceShoppingListToShopping;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Service\ConversionService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class AdvanceShoppingListToShoppingHandler
{


    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private ConversionService $conversionService,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws IngredientException
     */
    public function __invoke(AdvanceShoppingListToShoppingCommand $command): void
    {
        // Recuperation de la liste de course si elle existe
        $shoppingList = $this->shoppingListRepository->findOneById($command->shoppingListId);
        $shoppingList->toShopping();

        // On calcul chacun des ingredients dans l'unité d'achat
        foreach ($shoppingList->getIngredients() as $shopListIngredient) {
            $ingredient = $shopListIngredient->getIngredient();
            $quantityInPurchaseUnit = $this->conversionService->convertToPurchaseUnit(
                ingredient: $ingredient,
                quantity: $shopListIngredient->getQuantity(),
                unit: $shopListIngredient->getUnit(),
            );
            $shoppingList->addRow($ingredient, $quantityInPurchaseUnit);
        }

        $this->shoppingListRepository->save($shoppingList);
    }
}
