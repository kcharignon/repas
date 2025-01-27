<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

class ShoppingListException extends DomainException
{

    public static function shoppingListNotFound(): static
    {
        return new self("SHOPPING_LIST_NOT_FOUND", 404);
    }
}
