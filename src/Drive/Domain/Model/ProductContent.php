<?php

namespace Repas\Drive\Domain\Model;


/**
 * Ce que contient un produit de l'enseigne : une valeur dans une unité (500 g, 1 l, 6 pièces). Une valeur qui n'est pas un
 * nombre fini positif est inutilisable : `PackCalculator` la traite comme un contenu inconnu.
 */
final readonly class ProductContent
{
    /** Contenu plausible : entre 0,001 (la précision stockée) et 100 000 unités (100 kg, 100 l, 100 000 pièces). */
    public const float MIN_VALUE = 0.001;
    public const float MAX_VALUE = 100000.0;

    public function __construct(
        public float $value,
        public ContentUnit $unit,
    ) {
    }

    /**
     * Une valeur finie comprise entre `MIN_VALUE` et `MAX_VALUE` : ce qu'un agent peut raisonnablement lire sur le site.
     */
    public function isPlausible(): bool
    {
        return is_finite($this->value) && $this->value >= self::MIN_VALUE && $this->value <= self::MAX_VALUE;
    }
}
