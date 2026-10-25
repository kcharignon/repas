<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\ActiveShoppingListLines;
use Repas\Shared\Application\Dto\ListedLine;

/**
 * Double idiot : renvoie les lignes programmées telles quelles (`null` : pas de liste active) et enregistre ses appels.
 */
class ActiveShoppingListLinesInMemoryLoader implements ActiveShoppingListLines
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param ?list<ListedLine> $lines
     */
    public function __construct(
        private readonly ?array $lines = null,
    ) {
    }

    public function forUser(string $userId): ?array
    {
        $this->calls[] = $userId;

        return $this->lines;
    }
}
