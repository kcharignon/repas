<?php

namespace Repas\Repas\Application\Command\RemoveIngredientToShoppingList;

use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RemoveIngredientToShoppingListHandler
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
    public function __invoke(RemoveIngredientToShoppingListCommand $command): void
    {
        $activeShoppingList = $this->shoppingListRepository->findOneActivateByOwner($command->ownerId);
        if (!$activeShoppingList instanceof ShoppingList) {
            throw ShoppingListException::activeShoppingListNotFound();
        }
        $activeShoppingList->assertVersion($command->expectedVersion);

        // Un ancien slug de commun que le propriétaire a adopté désigne sa copie (ADR 0028 décision 4). On ne fait que retrouver ce que la
        // liste contient : aucune adoption ici
        $ingredientSlug = $this->slugResolver->resolveExisting($command->ownerId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;

        $purchaseUnitSlug = $this->ingredientRepository->findPurchaseUnitSlug($ingredientSlug);

        $activeShoppingList->removeIngredient($ingredientSlug, $purchaseUnitSlug);

        // On ajoute une quantité d'achat
        $activeShoppingList->subtractRow($ingredientSlug, 1);

        $this->shoppingListRepository->save($activeShoppingList);
    }

}
