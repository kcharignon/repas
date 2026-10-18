<?php

namespace Repas\Drive\Domain\Model;


/**
 * Où en est une ligne de l'estimation du coût d'une liste.
 */
enum EstimateLineStatus: string
{
    /** Produit préféré, prix mémorisé et nombre de produits sûr : la ligne entre dans le total. */
    case ESTIMATED = 'ESTIMATED';
    /** Aucun produit accepté dans le magasin courant. */
    case NO_PRODUCT = 'NO_PRODUCT';
    /** Le produit préféré n'a pas de prix mémorisé. */
    case NO_PRICE = 'NO_PRICE';
    /** Le nombre de produits ne se calcule pas de façon sûre (`PackCalculator`) : rien n'est deviné. */
    case TO_CHECK = 'TO_CHECK';
    /** « J'en ai toujours » : exclue du total et de la couverture. */
    case HAVE_AT_HOME = 'HAVE_AT_HOME';
}
