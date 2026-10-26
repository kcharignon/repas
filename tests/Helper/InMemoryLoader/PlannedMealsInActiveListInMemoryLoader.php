<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\PlannedMealsInActiveList;
use Repas\Shared\Application\Dto\ListedMeal;

/**
 * Double idiot : renvoie les repas programmés tels quels (`null` : pas de liste active) et enregistre ses appels.
 */
class PlannedMealsInActiveListInMemoryLoader implements PlannedMealsInActiveList
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param ?list<ListedMeal> $meals
     */
    public function __construct(
        private readonly ?array $meals = null,
    ) {
    }

    public function forUser(string $userId): ?array
    {
        $this->calls[] = $userId;

        return $this->meals;
    }
}
