<?php

namespace Repas\Repas\Application\Query\ListOtherPeopleRecipes;

use Repas\Repas\Application\Loader\RecipeLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListOtherPeopleRecipesQueryHandler
{
    public function __construct(
        private RecipeLoader $recipeLoader,
    ) {
    }

    /**
     * @return list<OtherPeopleRecipeDTO>
     */
    public function __invoke(ListOtherPeopleRecipesQuery $query): array
    {
        return $this->recipeLoader->listOfOtherPeople($query->userId);
    }
}
