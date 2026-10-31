<?php

namespace Repas\Repas\Application\OpenHostService;

use Repas\Repas\Application\Command\CheckRowsAddedToDriveBasket\CheckRowsAddedToDriveBasketCommand;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Shared\Application\Interface\CommandBusInterface;

/**
 * Open Host Service d'écriture du contexte `Repas` (ADR 0035) : cocher, dans la liste ENVOYÉE au drive de l'utilisateur, les lignes que le drive a
 * mises au panier (DRV-745). C'est la commande `CheckRowsAddedToDriveBasket` sur le bus transactionnel, sans règle de plus.
 */
final readonly class BasketLineCheckCommandOHS
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    /**
     * Idempotent ; un ingrédient absent de la liste est ignoré, jamais une ligne n'est décochée.
     *
     * @param list<string> $ingredientSlugs des ingrédients de l'utilisateur (déjà traduits par ses alias)
     *
     * @throws ShoppingListException liste inconnue (404), à un autre utilisateur (403), ou pas envoyée au drive (409) : rien n'est écrit
     */
    public function check(string $userId, string $shoppingListId, array $ingredientSlugs): void
    {
        $this->commandBus->dispatch(new CheckRowsAddedToDriveBasketCommand($userId, $shoppingListId, $ingredientSlugs));
    }
}
