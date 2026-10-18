<?php

namespace Repas\Drive\Domain\Model;


/**
 * Ce que l'agent fait d'une ligne du plan d'envoi.
 */
enum PlanLineStatus: string
{
    /** Au moins un produit accepté pour ce magasin : l'agent essaie les produits dans l'ordre. */
    case KNOWN = 'KNOWN';
    /** Aucun produit accepté : l'utilisateur en choisit un sur le site. */
    case NEW = 'NEW';
    /** « J'en ai toujours » : ligne ignorée. */
    case HAVE_AT_HOME = 'HAVE_AT_HOME';
}
