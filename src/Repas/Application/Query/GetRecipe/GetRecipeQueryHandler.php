<?php

namespace Repas\Repas\Application\Query\GetRecipe;

use Repas\Repas\Application\Loader\RecipeLoader;
use Repas\Repas\Domain\Exception\RecipeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetRecipeQueryHandler
{
    public function __construct(
        private RecipeLoader $recipeLoader,
    ) {
    }

    /**
     * @throws RecipeException
     */
    public function __invoke(GetRecipeQuery $query): RecipeDTO
    {
        return $this->recipeLoader->get($query->recipeId);
    }
}
