<?php

namespace Repas\Drive\Application\Port;

/**
 * Ce que le drive demande à la liste de courses quand l'agent a mis des articles au panier de l'enseigne (DRV-745) : les cocher. Adapté depuis
 * le contexte `Repas` (ADR 0035), qui juge le propriétaire et le statut de la liste.
 */
interface BasketLineCommandPort
{
    /**
     * Coche, dans la liste ENVOYÉE de l'utilisateur, les lignes de ces ingrédients. Idempotent : un ingrédient absent de la liste est ignoré, une
     * ligne déjà cochée le reste, jamais une ligne n'est décochée ; la version de la liste ne bouge pas et la liste n'est jamais terminée.
     *
     * @param list<string> $ingredientSlugs des ingrédients de l'utilisateur (déjà traduits par ses alias)
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException liste inconnue (404), à un autre utilisateur (403), ou pas envoyée au drive
     *                                                          (409 `SHOPPING_LIST_NOT_SENT`) : rien n'est écrit
     */
    public function check(string $userId, string $shoppingListId, array $ingredientSlugs): void;
}
