<?php

namespace Repas\Repas\Domain\Event;


readonly class CreateIngredientWithConversionEvent
{
    public function __construct(
        public string $ingredientSlug,
        public ?float $coefficient,
    ) {
    }
}
