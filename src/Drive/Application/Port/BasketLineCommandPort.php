<?php

namespace Repas\Drive\Application\Port;

use Repas\Drive\Application\Command\MarkLinesInBasket\NotAddedLine;

/**
 * Ce que le drive demande à la liste de courses quand l'agent a fini son passage (DRV-745, ADR 0041) : cocher les articles mis au panier de l'enseigne
 * et garder ce qui est arrivé aux autres. Adapté depuis le contexte `Repas` (ADR 0035), qui juge le propriétaire et le statut de la liste.
 */
interface BasketLineCommandPort
{
    /**
     * Écrit, dans la liste ENVOYÉE de l'utilisateur, l'issue de chaque ligne : coche celles de ces ingrédients qui sont au panier, garde l'issue des autres
     * sans les cocher. Idempotent : un ingrédient absent de la liste est ignoré, une ligne déjà cochée le reste (et garde son origine), jamais une ligne
     * n'est décochée ; la version de la liste ne bouge pas et la liste n'est jamais terminée.
     *
     * @param list<string> $ingredientSlugs des ingrédients de l'utilisateur (déjà traduits par ses alias) dont l'article est au panier
     * @param list<NotAddedLine> $notAdded les lignes que le passage n'a pas mises au panier (leurs slugs déjà traduits par les alias)
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException liste inconnue (404), à un autre utilisateur (403), ou pas envoyée au drive
     *                                                          (409 `SHOPPING_LIST_NOT_SENT`) : rien n'est écrit
     */
    public function check(string $userId, string $shoppingListId, array $ingredientSlugs, array $notAdded = []): void;
}
