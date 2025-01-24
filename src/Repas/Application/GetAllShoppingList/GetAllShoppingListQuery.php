<?php

namespace Repas\Repas\Application\GetAllShoppingList;


use Repas\User\Domain\Model\User;

readonly class GetAllShoppingListQuery
{
    public function __construct(
        public User $owner,
    ) {
    }
}
