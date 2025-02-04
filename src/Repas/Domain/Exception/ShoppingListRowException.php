<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

class ShoppingListRowException extends DomainException
{

    public static function notFound(string $id): static
    {
        return new static("ShoppingList row with id {$id} not found", 404);
    }
}
