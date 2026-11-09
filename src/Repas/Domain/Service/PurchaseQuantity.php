<?php

namespace Repas\Repas\Domain\Service;

use Repas\Repas\Domain\Model\ShoppingList;

/**
 * La quantité À ACHETER d'une ligne de liste : l'unité d'achat ne se coupe pas (0,4 plaquette de beurre, c'est 1 plaquette au magasin), donc on arrondit
 * toujours à l'entier supérieur (la version Twig affichait `round(0, 'ceil')`).
 *
 * C'est un arrondi de LECTURE. La ligne garde sa quantité exacte : 0,4 + 0,4 + 0,4 se lit « 2 » et retirer un repas retire ses 0,4, ce qui ne serait
 * plus juste si l'on arrondissait en base. Le bruit des flottants ne fait pas monter d'un cran (0,4 × 5 = 2,0000000000000004 se lit « 2 »).
 */
final class PurchaseQuantity
{
    /**
     * Au moins 1 : une ligne qui existe a toujours quelque chose à acheter (la quantité nulle ou négligeable supprime la ligne).
     */
    public static function roundedUp(float $quantity): float
    {
        return max(1.0, ceil($quantity - ShoppingList::FLOAT_DUST));
    }
}
