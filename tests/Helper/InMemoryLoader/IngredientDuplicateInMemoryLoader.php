<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\IngredientDuplicateLoader;
use Repas\Repas\Application\Query\ListDuplicateIngredients\DuplicateIngredientDTO;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni comparaison, ni tri) et enregistre ses appels.
 */
class IngredientDuplicateInMemoryLoader implements IngredientDuplicateLoader
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param list<DuplicateIngredientDTO> $duplicates
     */
    public function __construct(
        private readonly array $duplicates = [],
    ) {
    }

    public function duplicates(string $userId): array
    {
        $this->calls[] = $userId;

        return $this->duplicates;
    }
}
