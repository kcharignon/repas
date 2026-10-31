<?php

namespace Repas\Repas\Domain\Model;


/**
 * Nature de ce qu'une unité mesure (ADR 0035 : chaque contexte a la sienne ; `Drive` a la sienne, `Repas\Drive\Domain\Model\Dimension`, qui lit la même
 * chaîne publiée par l'Open Host Service de la liste de courses).
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
