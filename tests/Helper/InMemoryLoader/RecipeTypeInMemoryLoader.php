<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\RecipeTypeLoader;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class RecipeTypeInMemoryLoader implements RecipeTypeLoader
{
    public int $listCalls = 0;

    /**
     * @param list<RecipeTypeDTO> $recipeTypes
     */
    public function __construct(
        private readonly array $recipeTypes = [],
    ) {
    }

    public function list(): array
    {
        $this->listCalls++;

        return $this->recipeTypes;
    }
}
