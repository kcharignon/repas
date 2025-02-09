<?php

namespace Repas\Repas\Domain\Event;


readonly class NewShoppingListCreatedEvent
{

    public function __construct(
        public string $shoppingListId,
    ) {
    }
}
