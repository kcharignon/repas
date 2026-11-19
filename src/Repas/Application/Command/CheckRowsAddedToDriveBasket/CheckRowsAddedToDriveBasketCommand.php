<?php

namespace Repas\Repas\Application\Command\CheckRowsAddedToDriveBasket;

use Repas\Repas\Domain\Model\DriveLineAttempt;

/**
 * Ce que le passage au drive a fait des lignes de la liste ENVOYÉE de `$userId` (DRV-745, ADR 0041) : les lignes dont l'article est au panier (issue
 * `IN_CART`) sont cochées, les autres (refusé par le drive, indisponible, passé, déjà à la maison, en erreur) gardent leur issue et leur raison sans être
 * cochées. Les slugs arrivent déjà traduits par les alias de l'utilisateur, par l'appelant (le contexte `Drive`).
 */
readonly class CheckRowsAddedToDriveBasketCommand
{
    /**
     * @param list<DriveLineAttempt> $attempts un ingrédient au plus une fois
     */
    public function __construct(
        public string $userId,
        public string $shoppingListId,
        public array $attempts,
    ) {
    }
}
