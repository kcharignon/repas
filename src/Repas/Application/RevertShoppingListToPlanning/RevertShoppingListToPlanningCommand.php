<?php

namespace Repas\Repas\Application\RevertShoppingListToPlanning;


use Repas\User\Domain\Model\User;

readonly class RevertShoppingListToPlanningCommand
{

    public function __construct(
        public User $owner,
        public string $shoppingListId,
    ) {
    }
}
