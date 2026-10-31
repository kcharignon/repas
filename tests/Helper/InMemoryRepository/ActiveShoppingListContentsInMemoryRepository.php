<?php

namespace Repas\Tests\Helper\InMemoryRepository;

use Repas\Repas\Domain\Interface\ActiveShoppingListContents;
use Repas\Repas\Domain\Model\ActiveListLine;
use Repas\Repas\Domain\Model\ActiveListMeal;

/**
 * Double idiot du port : renvoie les lignes et les repas programmés tels quels (`null` : pas de liste active) et enregistre ses appels. Que la
 * lecture soit la bonne sur la base se prouve dans `ActiveShoppingListLinesPostgreSQLLoaderTest` et `PlannedMealsInActiveListPostgreSQLLoaderTest`.
 */
class ActiveShoppingListContentsInMemoryRepository implements ActiveShoppingListContents
{
    /** @var list<string> les appels à `lines` */
    public array $calls = [];

    /** @var list<string> les appels à `meals` */
    public array $mealCalls = [];

    /** @var list<string> les appels à `hasSentList` */
    public array $sentCalls = [];

    /**
     * @param ?list<ActiveListLine> $lines
     * @param ?list<ActiveListMeal> $meals
     */
    public function __construct(
        private ?array $lines = null,
        private bool $hasSentList = false,
        private ?array $meals = null,
    ) {
    }

    /**
     * @param ?list<ActiveListLine> $lines
     */
    public function withLines(?array $lines): self
    {
        $this->lines = $lines;

        return $this;
    }

    /**
     * @param ?list<ActiveListMeal> $meals
     */
    public function withMeals(?array $meals): self
    {
        $this->meals = $meals;

        return $this;
    }

    public function withSentList(bool $hasSentList = true): self
    {
        $this->hasSentList = $hasSentList;

        return $this;
    }

    public function lines(string $userId): ?array
    {
        $this->calls[] = $userId;

        return $this->lines;
    }

    public function meals(string $userId): ?array
    {
        $this->mealCalls[] = $userId;

        return $this->meals;
    }

    public function hasSentList(string $userId): bool
    {
        $this->sentCalls[] = $userId;

        return $this->hasSentList;
    }
}
