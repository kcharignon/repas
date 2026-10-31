<?php

namespace Repas\Tests\Helper\InMemoryLoader;

use Repas\Drive\Domain\Model\ShoppingListForDrive;
use Repas\Repas\Application\Loader\ShoppingListContentLoader;
use Repas\Repas\Domain\Exception\ShoppingListException;

/**
 * Double idiot : renvoie la liste programmée telle quelle, quel que soit l'identifiant demandé (introuvable si aucune n'est
 * programmée), et enregistre ses appels.
 *
 * La liste se programme avec le type du `Drive` (`ShoppingListForDrive`), que les tests de ses loaders construisent déjà : le double la
 * rend dans la forme du port de `Repas` (tableaux de valeurs simples), et c'est la vraie chaîne (Open Host Service, puis adapter) qui la
 * retransforme en liste du `Drive`.
 */
class ShoppingListContentInMemoryLoader implements ShoppingListContentLoader
{
    /** @var list<string> identifiants reçus, dans l'ordre des appels */
    public array $getCalls = [];

    public function __construct(
        private readonly ?ShoppingListForDrive $shoppingList = null,
    ) {
    }

    public function get(string $shoppingListId): array
    {
        $this->getCalls[] = $shoppingListId;

        $list = $this->shoppingList ?? throw ShoppingListException::shoppingListNotFound($shoppingListId);

        return [
            'id' => $list->id,
            'ownerId' => $list->ownerId,
            'status' => $list->status,
            'version' => $list->version,
            'lines' => array_map(static fn(object $line): array => get_object_vars($line), $list->lines),
        ];
    }
}
