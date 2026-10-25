<?php

namespace Repas\Shared\Application\Interface;

/**
 * Port par lequel le contexte `Voice` ajoute un article à la liste de courses de l'utilisateur (ADR 0029 décision 5, VOX-103). Défini
 * ici, implémenté dans `Repas\Infrastructure\Voice` (ADR 0013 règle 8 : `Voice` n'importe pas `Repas`).
 *
 * La liste active reçoit la ligne (une unité d'achat) ; sans liste active (aucune, ou envoyée au drive), une nouvelle liste est créée.
 * Une liste envoyée n'est jamais modifiée.
 */
interface VoiceShoppingList
{
    /**
     * @param string $ingredientSlug un ingrédient de l'utilisateur, ou un ancien slug que ses alias traduisent
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException l'ingrédient n'existe pas ou est à un autre utilisateur (rien n'est écrit)
     */
    public function addItem(string $userId, string $ingredientSlug): AddedItem;
}
