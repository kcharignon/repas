<?php

namespace Repas\Repas\Domain\Event;


readonly class CreateIngredientWithConversionEvent
{
    /**
     * @param string $userId le créateur de l'ingrédient : la conversion est écrite pour lui, sur un ingrédient qui est à lui
     */
    public function __construct(
        public string $ingredientSlug,
        public ?float $coefficient,
        public string $userId,
    ) {
    }
}
