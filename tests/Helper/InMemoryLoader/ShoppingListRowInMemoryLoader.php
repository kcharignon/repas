<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\ShoppingListRowLoader;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListRowDTO;
use Repas\Repas\Domain\Exception\ShoppingListRowException;

/**
 * Double idiot : renvoie le DTO programmé tel quel et enregistre ses appels.
 */
class ShoppingListRowInMemoryLoader implements ShoppingListRowLoader
{
    /** @var list<string> */
    public array $getCalls = [];

    public function __construct(
        private readonly ?ShoppingListRowDTO $row = null,
    ) {
    }

    public function get(string $rowId): ShoppingListRowDTO
    {
        $this->getCalls[] = $rowId;

        return $this->row ?? throw ShoppingListRowException::notFound($rowId);
    }
}
