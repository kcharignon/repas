<?php

namespace Repas\Repas\Domain\Model;

/**
 * Qui a coché une ligne de liste de courses (ADR 0041) : la personne, à la main, ou le passage au drive. Posée avec la coche, effacée avec elle ; une
 * ligne cochée avant l'ADR 0041 n'a pas d'origine connue (`null`).
 */
enum RowCheckSource: string
{
    /** La personne a coché la ligne dans la liste. */
    case MANUAL = 'MANUAL';
    /** Le passage au drive a mis l'article au panier (DRV-745). */
    case DRIVE = 'DRIVE';
}
