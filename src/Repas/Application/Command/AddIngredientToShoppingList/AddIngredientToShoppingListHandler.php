<?php

namespace Repas\Repas\Application\Command\AddIngredientToShoppingList;

use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class AddIngredientToShoppingListHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private IngredientRepository $ingredientRepository,
        private IngredientSlugResolver $slugResolver,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws IngredientException
     */
    public function __invoke(AddIngredientToShoppingListCommand $command): void
    {
        $shoppingListActive = $this->shoppingListRepository->findOneActivateByOwner($command->ownerId);
        if (!$shoppingListActive instanceof ShoppingList) {
            throw ShoppingListException::activeShoppingListNotFound();
        }
        $shoppingListActive->assertVersion($command->expectedVersion);

        // Un ancien slug (ancien commun dont le propriétaire a reçu une copie, ingrédient fusionné) est remplacé par l'ingrédient actuel
        // du propriétaire (ADR 0028 décision 4)
        $ingredientSlug = $this->slugResolver->resolve($command->ownerId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;

        $purchaseUnitSlug = $this->ingredientRepository->findPurchaseUnitSlugUsableBy($ingredientSlug, $command->ownerId);
        $shoppingListActive->addIngredient($ingredientSlug, $purchaseUnitSlug);

        $shoppingListActive->addRow($ingredientSlug, $purchaseUnitSlug, 1);

        $this->shoppingListRepository->save($shoppingListActive);
    }
}
