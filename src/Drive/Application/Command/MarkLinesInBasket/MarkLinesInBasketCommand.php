<?php

namespace Repas\Drive\Application\Command\MarkLinesInBasket;

/**
 * Ce que le passage au drive a fait des lignes d'une liste envoyée (DRV-745, ADR 0041) : les articles que l'agent a mis au panier de l'enseigne (leurs
 * lignes seront cochées) et ceux qu'il n'y a pas mis (leur issue est gardée, ils restent décochés). L'utilisateur vient du jeton, jamais du corps.
 */
readonly class MarkLinesInBasketCommand
{
    /** Le plus de slugs qu'un envoi rapporte : bien plus que les lignes d'une vraie liste, de quoi borner le corps et l'instruction SQL. */
    public const int MAX_SLUGS = 1000;

    /**
     * @param list<string> $ingredientSlugs les articles au panier, distincts, bornés par `MAX_SLUGS`
     * @param list<NotAddedLine> $notAdded les articles que le passage n'a pas mis au panier, bornés par `MAX_SLUGS`
     */
    public function __construct(
        public string $userId,
        public string $shoppingListId,
        public array $ingredientSlugs,
        public array $notAdded = [],
    ) {
    }
}
