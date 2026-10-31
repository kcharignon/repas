<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\ShoppingListTypeLoader;
use Repas\Repas\Application\Query\GetShoppingListType\ShoppingListTypeDTO;
use Repas\Repas\Application\Query\ListShoppingListTypes\ShoppingListTypeSummaryDTO;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class ShoppingListTypeInMemoryLoader implements ShoppingListTypeLoader
{
    /** @var list<string> */
    public array $listCalls = [];

    /** @var list<array{string, string}> */
    public array $getCalls = [];

    /**
     * @param list<ShoppingListTypeSummaryDTO> $summaries
     */
    public function __construct(
        private readonly array $summaries = [],
        private readonly ?ShoppingListTypeDTO $detail = null,
    ) {
    }

    public function list(string $userId): array
    {
        $this->listCalls[] = $userId;

        return $this->summaries;
    }

    public function get(string $id, string $userId): ShoppingListTypeDTO
    {
        $this->getCalls[] = [$id, $userId];

        return $this->detail ?? throw new \LogicException('Aucun type programmé.');
    }
}
