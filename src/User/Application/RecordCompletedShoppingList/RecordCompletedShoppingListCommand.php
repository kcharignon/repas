<?php

namespace Repas\User\Application\RecordCompletedShoppingList;


use DateTimeImmutable;

readonly class RecordCompletedShoppingListCommand
{

    public function __construct(
        public string $userId,
        public string $shoppingListId,
        public DateTimeImmutable $createdAt,
        public int $mealCount,
        public int $ingredientCount,
        public int $rowCount,
    ) {
    }
}
