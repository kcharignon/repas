<?php

namespace Repas\Repas\Application\Command\DecreaseShoppingListRowQuantity;

use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Retire 1 à une LIGNE précise d'une liste, depuis l'écran de la liste (voir `IncreaseShoppingListRowQuantityHandler`). La ligne perd 1 en unité d'achat et
 * disparaît quand il n'en reste presque rien (`ShoppingList::removeQuantity`, le geste du retrait à la main d'Alexa) : 0,4 plaquette lue « 1 » disparaît
 * d'un seul retrait, 1,4 lue « 2 » devient 0,4 lue « 1 ». Ce que la liste retient de l'ajout à la main part avec elle, et une ligne devenue plus petite que
 * ses parties les réduit dans la même proportion (ADR 0038 décision 7). Une liste envoyée au drive ne bouge pas (`409 SHOPPING_LIST_SENT`).
 */
#[AsMessageHandler]
readonly class DecreaseShoppingListRowQuantityHandler
{
    public function __construct(
        private ShoppingListRowRepository $shoppingListRowRepository,
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    /**
     * @throws ShoppingListRowException
     * @throws ShoppingListException
     */
    public function __invoke(DecreaseShoppingListRowQuantityCommand $command): void
    {
        $row = $this->shoppingListRowRepository->findOneById($command->shoppingListRowId);

        $shoppingList = $this->shoppingListRepository->findOneById($row->getShoppingListId());
        $shoppingList->assertVersion($command->expectedVersion);

        $shoppingList->removeQuantity($row->getIngredientSlug(), 1.0);

        $this->shoppingListRepository->save($shoppingList);
    }
}
