<?php

namespace Repas\Repas\Application\AddIngredientToShoppingList;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class AddIngredientToShoppingListHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ShoppingListRepository $shoppingListRepository,
        private IngredientRepository $ingredientRepository,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws UserException
     * @throws IngredientException
     */
    public function __invoke(AddIngredientToShoppingListCommand $command): void
    {
        $owner = $this->userRepository->findOneById($command->ownerId);
        $shoppingListActive = $this->shoppingListRepository->findOneActivateByOwner($owner);
        if (!$shoppingListActive instanceof ShoppingList) {
            throw ShoppingListException::activeShoppingListNotFound();
        }

        $ingredient = $this->ingredientRepository->findOneBySlug($command->ingredientSlug);
        $shoppingListActive->addIngredient($ingredient);

        $shoppingListActive->addRow($ingredient, 1);

        $this->shoppingListRepository->save($shoppingListActive);
    }
}
