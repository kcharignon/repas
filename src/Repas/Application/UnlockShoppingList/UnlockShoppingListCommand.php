<?php

namespace Repas\Repas\Application\UnlockShoppingList;


use Repas\User\Domain\Model\User;

readonly class UnlockShoppingListCommand
{

    public function __construct(
        public User $owner,
        public string $shoppingListId,
    ) {
    }
}
