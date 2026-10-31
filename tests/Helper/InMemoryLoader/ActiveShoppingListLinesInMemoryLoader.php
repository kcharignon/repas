<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\ActiveShoppingListLines;
use Repas\Repas\Domain\Model\ActiveListLine;

/**
 * Double idiot : renvoie les lignes programmées telles quelles (`null` : pas de liste active) et enregistre ses appels.
 */
class ActiveShoppingListLinesInMemoryLoader implements ActiveShoppingListLines
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> les appels à `hasSentList` */
    public array $sentCalls = [];

    /**
     * @param ?list<ActiveListLine> $lines
     */
    public function __construct(
        private readonly ?array $lines = null,
        private readonly bool $hasSentList = false,
    ) {
    }

    public function forUser(string $userId): ?array
    {
        $this->calls[] = $userId;

        return $this->lines;
    }

    public function hasSentList(string $userId): bool
    {
        $this->sentCalls[] = $userId;

        return $this->hasSentList;
    }
}
