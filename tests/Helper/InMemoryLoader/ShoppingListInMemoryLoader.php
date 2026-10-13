<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\ShoppingListLoader;
use Repas\Repas\Application\Query\GetActiveShoppingListSummary\ActiveShoppingListSummaryDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListDetailDTO;
use Repas\Repas\Application\Query\ListShoppingLists\ShoppingListDTO;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Exception\ShoppingListException;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class ShoppingListInMemoryLoader implements ShoppingListLoader
{
    /** @var list<string> identifiants de propriétaire reçus, dans l'ordre des appels */
    public array $listByOwnerCalls = [];

    /** @var list<string> identifiants de propriétaire reçus, dans l'ordre des appels */
    public array $getActiveSummaryCalls = [];

    /** @var list<string> */
    public array $getCalls = [];

    /** @var list<string> */
    public array $getCurrentCalls = [];

    /** @var list<string> */
    public array $getByMealIdCalls = [];

    /**
     * @param list<ShoppingListDTO> $shoppingLists
     */
    public function __construct(
        private readonly array $shoppingLists = [],
        private readonly ?ActiveShoppingListSummaryDTO $activeSummary = null,
        private readonly ?ShoppingListDetailDTO $detail = null,
    ) {
    }

    public function listByOwner(string $ownerId): array
    {
        $this->listByOwnerCalls[] = $ownerId;

        return $this->shoppingLists;
    }

    public function getActiveSummary(string $ownerId): ActiveShoppingListSummaryDTO
    {
        $this->getActiveSummaryCalls[] = $ownerId;

        return $this->activeSummary ?? new ActiveShoppingListSummaryDTO(null, [], []);
    }
    public function get(string $id): ShoppingListDetailDTO
    {
        $this->getCalls[] = $id;

        return $this->detail ?? throw ShoppingListException::shoppingListNotFound($id);
    }

    public function getCurrent(string $ownerId): ?ShoppingListDetailDTO
    {
        $this->getCurrentCalls[] = $ownerId;

        return $this->detail;
    }

    public function getByMealId(string $mealId): ShoppingListDetailDTO
    {
        $this->getByMealIdCalls[] = $mealId;

        return $this->detail ?? throw MealException::notFound();
    }
}
