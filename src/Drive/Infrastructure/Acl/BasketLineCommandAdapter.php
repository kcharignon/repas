<?php

namespace Repas\Drive\Infrastructure\Acl;

use Repas\Drive\Application\Port\BasketLineCommandPort;
use Repas\Repas\Application\OpenHostService\BasketLineCheckCommandOHS;

/**
 * Couche anticorruption (ADR 0035) : cocher les lignes d'une liste envoyée que le drive a mises au panier, par l'Open Host Service d'écriture de
 * `Repas`, tel quel (des identifiants de part et d'autre). Les refus de `Repas` (liste inconnue, à un autre, pas envoyée) remontent tels quels.
 */
final readonly class BasketLineCommandAdapter implements BasketLineCommandPort
{
    public function __construct(
        private BasketLineCheckCommandOHS $basketLines,
    ) {
    }

    public function check(string $userId, string $shoppingListId, array $ingredientSlugs): void
    {
        $this->basketLines->check($userId, $shoppingListId, $ingredientSlugs);
    }
}
