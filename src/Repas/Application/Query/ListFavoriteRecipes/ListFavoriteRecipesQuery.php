<?php

namespace Repas\Repas\Application\Query\ListFavoriteRecipes;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListFavoriteRecipesQuery implements QueryInterface
{
    public function __construct(
        public string $authorId,
    ) {
    }
}
