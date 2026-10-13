<?php

namespace Repas\Repas\Application\Query\ListOtherPeopleRecipes;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListOtherPeopleRecipesQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
    ) {
    }
}
