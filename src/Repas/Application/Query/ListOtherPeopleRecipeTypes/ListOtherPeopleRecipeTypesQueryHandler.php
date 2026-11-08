<?php

namespace Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes;

use Repas\Repas\Application\Loader\RecipeLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListOtherPeopleRecipeTypesQueryHandler
{
    public function __construct(
        private RecipeLoader $recipeLoader,
    ) {
    }

    public function __invoke(ListOtherPeopleRecipeTypesQuery $query): OtherPeopleRecipeTypesDTO
    {
        return $this->recipeLoader->listOtherPeopleTypes($query->userId, $query->isAdministrator);
    }
}
