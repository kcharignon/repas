<?php

namespace Repas\Shared\Application\Interface;

/**
 * Port par lequel le contexte `Voice` ajoute un article à la liste de courses de l'utilisateur (ADR 0029 décision 5, VOX-103). Défini
 * ici, implémenté dans `Repas\Infrastructure\Voice` (ADR 0013 règle 8 : `Voice` n'importe pas `Repas`).
 *
 * La liste active reçoit la ligne (une unité d'achat par défaut, ou la quantité et l'unité dites), ou s'ajoute à la ligne de cet ingrédient
 * qui y est déjà ; sans liste active (aucune, ou envoyée au drive), une nouvelle liste est créée. Une liste envoyée n'est jamais modifiée.
 */
interface VoiceShoppingList
{
    /**
     * @param string  $ingredientSlug un ingrédient de l'utilisateur, ou un ancien slug que ses alias traduisent
     * @param int     $quantity       combien, de 1 à 99
     * @param ?string $unitSlug       l'unité dite : utilisée seulement si l'ingrédient la connaît (son unité d'achat ou une de ses unités
     *                                compatibles) ; sinon la ligne garde son unité (`AddedItem::unitSubstituted`)
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException l'ingrédient n'existe pas ou est à un autre utilisateur, ou la quantité est hors de
     *                                                          1 à 99 (rien n'est écrit)
     */
    public function addItem(string $userId, string $ingredientSlug, int $quantity = 1, ?string $unitSlug = null): AddedItem;
}
