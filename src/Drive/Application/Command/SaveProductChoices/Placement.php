<?php

namespace Repas\Drive\Application\Command\SaveProductChoices;


enum Placement: string
{
    /** Le nouveau produit préféré : rang 1, les autres descendent d'un rang. */
    case PRIMARY = 'PRIMARY';
    /** Produit de secours : après les autres. */
    case FALLBACK = 'FALLBACK';
}
