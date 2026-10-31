<?php

namespace Repas\Voice\Infrastructure\Acl;

use Repas\Repas\Application\OpenHostService\ShoppingListItemCommandOHS;
use Repas\Voice\Application\Port\ShoppingListItemCommandPort;
use Repas\Voice\Domain\Model\AddedItem;
use Repas\Voice\Domain\Model\RemovedItem;

/**
 * Couche anticorruption (ADR 0035) : ce que la liste de courses de `Repas` a fait d'un ajout ou d'un retrait devient ce dont la réponse vocale
 * a besoin. Rien n'est décidé ici ; les erreurs de `Repas` ne sont pas attrapées (la commande entière échoue, rien n'est écrit).
 */
final readonly class ShoppingListItemCommandAdapter implements ShoppingListItemCommandPort
{
    public function __construct(
        private ShoppingListItemCommandOHS $items,
    ) {
    }

    public function addItem(string $userId, string $ingredientSlug, int $quantity = 1, ?string $unitSlug = null): AddedItem
    {
        $added = $this->items->addItem($userId, $ingredientSlug, $quantity, $unitSlug);

        return new AddedItem(
            listCreated: $added->listCreated,
            previousListSent: $added->previousListSent,
            quantity: $added->quantity,
            unitSlug: $added->unitSlug,
            unitName: $added->unitName,
            lineExisted: $added->lineExisted,
            unitSubstituted: $added->unitSubstituted,
            addedQuantity: $added->addedQuantity,
        );
    }

    public function removeItem(string $userId, string $ingredientSlug, int $quantity = 1, ?string $unitSlug = null): RemovedItem
    {
        $removed = $this->items->removeItem($userId, $ingredientSlug, $quantity, $unitSlug);

        return new RemovedItem(
            found: $removed->found,
            remaining: $removed->remaining,
            removed: $removed->removed,
            unitSlug: $removed->unitSlug,
            unitName: $removed->unitName,
            unitSubstituted: $removed->unitSubstituted,
        );
    }
}
