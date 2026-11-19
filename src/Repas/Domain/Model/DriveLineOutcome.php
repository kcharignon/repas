<?php

namespace Repas\Repas\Domain\Model;

/**
 * Ce qui est arrivé à une ligne au dernier passage au drive (ADR 0041) : le rapport que le site fait de ce que l'agent a lu sur la page de l'enseigne.
 * Une trace pour comprendre une liste (pourquoi cette ligne n'est-elle pas cochée ?), jamais une règle : rien ne s'appuie dessus.
 */
enum DriveLineOutcome: string
{
    /** L'article est au panier de l'enseigne : la ligne est cochée par le passage. */
    case IN_CART = 'IN_CART';
    /** Le produit a été choisi mais le panier de l'enseigne n'en a rien pris (le drive l'a refusé) : la ligne reste décochée. */
    case NOT_IN_CART = 'NOT_IN_CART';
    /** Aucun produit disponible, et la personne n'en a pas choisi d'autre. */
    case UNAVAILABLE = 'UNAVAILABLE';
    /** La personne a passé la ligne cette fois. */
    case SKIPPED = 'SKIPPED';
    /** La personne en a toujours à la maison. */
    case HAVE_AT_HOME = 'HAVE_AT_HOME';
    /** Une erreur a arrêté la ligne ; le code de l'erreur est la raison. */
    case FAILED = 'FAILED';
}
