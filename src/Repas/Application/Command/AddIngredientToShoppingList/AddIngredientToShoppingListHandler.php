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

        // Le commun que le propriétaire a adopté (ou adopte à la première utilisation) est remplacé par sa copie (ADR 0028 décision 4)
        $ingredientSlug = $this->slugResolver->resolveForUser($command->ownerId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;

        $purchaseUnitSlug = $this->ingredientRepository->findPurchaseUnitSlugUsableBy($ingredientSlug, $command->ownerId);
        $shoppingListActive->addIngredient($ingredientSlug, $purchaseUnitSlug);

        $shoppingListActive->addRow($ingredientSlug, $purchaseUnitSlug, 1);

        $this->shoppingListRepository->save($shoppingListActive);
    }
}
