<?php

namespace Repas\Repas\Application\Query\GetShoppingListByMeal;

use Repas\Repas\Application\Loader\ShoppingListLoader;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListDetailDTO;
use Repas\Repas\Domain\Exception\MealException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetShoppingListByMealQueryHandler
{
    public function __construct(
        private ShoppingListLoader $shoppingListLoader,
    ) {
    }

    /**
     * @throws MealException
     */
    public function __invoke(GetShoppingListByMealQuery $query): ShoppingListDetailDTO
    {
        return $this->shoppingListLoader->getByMealId($query->mealId);
    }
}
