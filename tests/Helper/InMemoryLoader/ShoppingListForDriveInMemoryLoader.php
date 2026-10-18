<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Shared\Application\Dto\ShoppingListForDrive;
use Repas\Shared\Application\Interface\ShoppingListForDriveLoader;

/**
 * Double idiot : renvoie la liste programmée telle quelle, quel que soit l'identifiant demandé (introuvable si aucune n'est
 * programmée), et enregistre ses appels.
 */
class ShoppingListForDriveInMemoryLoader implements ShoppingListForDriveLoader
{
    /** @var list<string> identifiants reçus, dans l'ordre des appels */
    public array $getCalls = [];

    public function __construct(
        private readonly ?ShoppingListForDrive $shoppingList = null,
    ) {
    }

    public function get(string $shoppingListId): ShoppingListForDrive
    {
        $this->getCalls[] = $shoppingListId;

        return $this->shoppingList ?? throw ShoppingListException::shoppingListNotFound($shoppingListId);
    }
}
