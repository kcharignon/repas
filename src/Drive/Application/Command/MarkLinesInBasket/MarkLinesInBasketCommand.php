<?php

namespace Repas\Drive\Application\Command\MarkLinesInBasket;

/**
 * Les articles que l'agent a mis au panier de l'enseigne pour une liste envoyée (DRV-745) : leurs lignes seront cochées. L'utilisateur vient du
 * jeton, jamais du corps.
 */
readonly class MarkLinesInBasketCommand
{
    /** Le plus de slugs qu'un envoi rapporte : bien plus que les lignes d'une vraie liste, de quoi borner le corps et l'instruction SQL. */
    public const int MAX_SLUGS = 1000;

    /**
     * @param list<string> $ingredientSlugs distincts, bornés par `MAX_SLUGS`
     */
    public function __construct(
        public string $userId,
        public string $shoppingListId,
        public array $ingredientSlugs,
    ) {
    }
}
