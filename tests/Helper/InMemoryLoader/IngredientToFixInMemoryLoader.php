<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\IngredientToFixLoader;
use Repas\Repas\Application\Query\ListIngredientsToFix\IngredientsToFixDTO;

/**
 * Double idiot : renvoie le DTO programmé tel quel (ni jugement, ni tri) et enregistre ses appels.
 */
class IngredientToFixInMemoryLoader implements IngredientToFixLoader
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        private readonly IngredientsToFixDTO $toFix = new IngredientsToFixDTO([], 0),
    ) {
    }

    public function toFix(string $userId): IngredientsToFixDTO
    {
        $this->calls[] = $userId;

        return $this->toFix;
    }
}
