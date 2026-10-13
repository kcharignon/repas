<?php

namespace Repas\Repas\Application\Query\ListRecipesByType;

use Repas\Repas\Application\Loader\RecipeLoader;
use Repas\Repas\Domain\Exception\RecipeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListRecipesByTypeQueryHandler
{
    public function __construct(
        private RecipeLoader $recipeLoader,
    ) {
    }

    /**
     * @throws RecipeException
     */
    public function __invoke(ListRecipesByTypeQuery $query): RecipeTypeRecipesDTO
    {
        return $this->recipeLoader->listByAuthorAndType($query->authorId, $query->typeSlug);
    }
}
