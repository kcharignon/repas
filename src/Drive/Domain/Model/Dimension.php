<?php

namespace Repas\Drive\Domain\Model;


/**
 * Nature de ce que mesure l'unité d'une ligne de liste, dans la langue de `Drive` (ADR 0035). Les valeurs sont celles que publie
 * `Repas` (la chaîne `unitDimension` de l'Open Host Service de la liste de courses) ; le calcul des conditionnements (`PackCalculator`)
 * s'en sert.
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
