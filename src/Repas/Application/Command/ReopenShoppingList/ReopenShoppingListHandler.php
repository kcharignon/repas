<?php

namespace Repas\Repas\Application\Command\ReopenShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class ReopenShoppingListHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    public function __invoke(ReopenShoppingListCommand $command): void
    {
        $shoppingList = $this->shoppingListRepository->findOneById($command->shoppingListId);
        $shoppingList->assertVersion($command->expectedVersion);

        // L'autre liste en cours du même type (la liste à rouvrir est `SENT`, donc ce n'est pas elle), lue avant la transition (ADR 0036 décision 6)
        $activeShoppingList = $this->shoppingListRepository->findOneActiveByOwnerAndType($shoppingList->getOwnerId(), $shoppingList->getListTypeId());

        // Refuse une liste qui n'est pas envoyée avant d'enregistrer quoi que ce soit
        $shoppingList->reopen();

        // Comme à l'activation : la liste en cours du même type, s'il y en a une, est mise en pause
        if ($activeShoppingList instanceof ShoppingList) {
            $activeShoppingList->pause();
            $this->shoppingListRepository->save($activeShoppingList);
        }

        $this->shoppingListRepository->save($shoppingList);
    }
}
