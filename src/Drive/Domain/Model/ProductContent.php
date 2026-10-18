<?php

namespace Repas\Drive\Domain\Model;


/**
 * Ce que contient un produit de l'enseigne : une valeur dans une unité (500 g, 1 l, 6 pièces). Une valeur qui n'est pas un
 * nombre fini positif est inutilisable : `PackCalculator` la traite comme un contenu inconnu.
 */
final readonly class ProductContent
{
    public function __construct(
        public float $value,
        public ContentUnit $unit,
    ) {
    }
}
