<?php

namespace Repas\Shared\Domain\Model;


/**
 * Nature de ce qu'une unité mesure. Dans `Shared` car `Repas` (unités) et `Drive` (calcul des conditionnements) l'utilisent
 * et qu'un contexte n'en importe pas un autre (docs/drive/architecture.md § 6).
 */
enum Dimension: string
{
    /** Poids : gramme, kilo. */
    case WEIGHT = 'WEIGHT';
    /** Volume : millilitre, centilitre, litre. */
    case VOLUME = 'VOLUME';
    /** Pièces : unité. */
    case COUNT = 'COUNT';
    /** Conditionnement : paquet, boîte, bouteille… */
    case PACKAGE = 'PACKAGE';
}
