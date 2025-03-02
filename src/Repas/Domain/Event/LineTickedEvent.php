<?php

namespace Repas\Repas\Domain\Event;


class LineTickedEvent
{

    public function __construct(
        public string $shoppingListId,
    ) {
    }
}
