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
     * La page demandée est bornée ici aussi : le cas d'usage ne se fie pas à son appelant.
     */
    public function __invoke(ListOtherPeopleRecipesQuery $query): OtherPeopleRecipesDTO
    {
        return $this->recipeLoader->listOfOtherPeople(
            $query->userId,
            min(max($query->limit, 1), ListOtherPeopleRecipesQuery::MAX_LIMIT),
            max($query->offset, 0),
        );
    }
}
