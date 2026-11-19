<?php

namespace Repas\Drive\Domain\Model;

/**
 * Pourquoi une ligne du plan n'a pas été mise au panier de l'enseigne : l'issue que l'agent rapporte pour elle (ADR 0041). Les valeurs sont celles
 * publiées par le contrat `POST /drive/plans/{id}/basket-lines` ; le contexte `Repas` les range de son côté.
 */
enum NotAddedOutcome: string
{
    /** Le produit a été choisi mais le panier de l'enseigne n'en a rien pris. */
    case NOT_IN_CART = 'NOT_IN_CART';
    /** Aucun produit disponible, et aucun autre choisi. */
    case UNAVAILABLE = 'UNAVAILABLE';
    /** « Passer cette fois ». */
    case SKIPPED = 'SKIPPED';
    /** « J'en ai toujours ». */
    case HAVE_AT_HOME = 'HAVE_AT_HOME';
    /** Une erreur a arrêté la ligne (raison : le code de l'erreur). */
    case FAILED = 'FAILED';
}
