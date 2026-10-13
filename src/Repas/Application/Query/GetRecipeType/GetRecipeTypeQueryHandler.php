<?php

namespace Repas\Repas\Application\Query\GetRecipeType;

use Repas\Repas\Application\Loader\RecipeTypeLoader;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Repas\Domain\Exception\RecipeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetRecipeTypeQueryHandler
{
    public function __construct(
        private RecipeTypeLoader $loader,
    ) {
    }

    /**
     * @throws RecipeException
     */
    public function __invoke(GetRecipeTypeQuery $query): RecipeTypeDTO
    {
        return $this->loader->get($query->slug);
    }
}
