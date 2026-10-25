<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Application\Interface\AddedItem;
use Repas\Shared\Application\Interface\VoiceShoppingList;
use Throwable;

/**
 * Double du port `VoiceShoppingList` : note les ajouts demandés, renvoie le résultat programmé (liste existante par défaut) ou lève
 * l'exception programmée. La liste elle-même se prouve dans les tests de `Repas` et de bout en bout.
 */
final class VoiceShoppingListInMemory implements VoiceShoppingList
{
    /** @var list<array{string, string}> les ajouts demandés `[userId, ingredientSlug]`, dans l'ordre */
    public array $added = [];

    private AddedItem $result;
    private ?Throwable $failure = null;

    public function __construct()
    {
        $this->result = new AddedItem(listCreated: false, previousListSent: false);
    }

    public function willReturn(AddedItem $result): self
    {
        $this->result = $result;

        return $this;
    }

    public function willThrow(Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function addItem(string $userId, string $ingredientSlug): AddedItem
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->added[] = [$userId, $ingredientSlug];

        return $this->result;
    }
}
