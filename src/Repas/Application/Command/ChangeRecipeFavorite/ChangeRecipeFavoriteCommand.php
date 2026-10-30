<?php

namespace Repas\Repas\Application\Command\ChangeRecipeFavorite;


readonly class ChangeRecipeFavoriteCommand
{

    public function __construct(
        public string $recipeId,
        public string $userId,
        public bool $favorite,
        public ?int $expectedVersion = null,
    ) {
    }
}
