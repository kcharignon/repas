<?php

namespace Repas\Drive\Domain\Model;


/**
 * Unité du prix à l'unité de mesure d'un produit (« 2,50 € le kilo »), telle que l'agent la lit chez l'enseigne.
 */
enum PriceUnit: string
{
    case KG = 'kg';
    case L = 'l';
    case PIECE = 'piece';
}
