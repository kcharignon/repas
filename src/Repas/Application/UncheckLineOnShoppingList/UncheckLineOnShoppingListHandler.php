<?php

namespace Repas\Repas\Application\UncheckLineOnShoppingList;

use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UncheckLineOnShoppingListHandler
{
    public function __construct(
        private ShoppingListRowRepository $shoppingListRowRepository,
    ) {
    }

    public function __invoke(UncheckLineOnShoppingListCommand $command): void
    {
        $row = $this->shoppingListRowRepository->findOneById($command->shoppingListRowId);

        $row->uncheck();

        $this->shoppingListRowRepository->save($row);
    }
}
