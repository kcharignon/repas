<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\RecipeTypeLoader;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeCountDTO;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class RecipeTypeInMemoryLoader implements RecipeTypeLoader
{
    public int $listCalls = 0;

    /** @var list<string> */
    public array $listUserIds = [];

    /** @var list<string> */
    public array $getCalls = [];

    /**
     * @param list<RecipeTypeCountDTO> $recipeTypes
     */
    public function __construct(
        private readonly array $recipeTypes = [],
        private readonly ?RecipeTypeDTO $recipeType = null,
    ) {
    }

    public function list(string $userId): array
    {
        $this->listUserIds[] = $userId;
        $this->listCalls++;

        return $this->recipeTypes;
    }

    public function get(string $slug): RecipeTypeDTO
    {
        $this->getCalls[] = $slug;

        return $this->recipeType ?? throw RecipeException::typeNotFound($slug);
    }
}
