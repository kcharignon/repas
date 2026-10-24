<?php

namespace Repas\Drive\Application\Command\SaveProductChoices;


/**
 * Ce que l'utilisateur a décidé pour un ingrédient, tel que l'agent le rapporte (docs/drive/architecture.md § 5.3).
 */
enum ChoiceAction: string
{
    /** Le produit rejoint les produits acceptés (`Placement`) ; un produit refusé qu'on ajoute n'est plus refusé. */
    case ADD_PRODUCT = 'ADD_PRODUCT';
    /** Le produit n'est plus accepté et ne sera plus jamais proposé. */
    case REJECT_PRODUCT = 'REJECT_PRODUCT';
    /** « J'en ai toujours » : les produits de l'ingrédient sont effacés. */
    case HAVE_AT_HOME = 'HAVE_AT_HOME';
    /**
     * L'agent a remis au panier un produit déjà accepté et en a relu le prix : le produit connu est rafraîchi, la préférence
     * ne change pas. Sans effet si le produit n'est pas accepté pour cet ingrédient.
     */
    case REFRESH_PRICE = 'REFRESH_PRICE';
    /**
     * DRV-653 : la recherche qui a fait trouver un produit pris « cette fois seulement » reste la bonne recherche de l'ingrédient : elle
     * est mémorisée sans toucher aux produits acceptés ni refusés. La préférence est créée si elle n'existe pas (aucun produit accepté :
     * la ligne du plan reste `NEW`). Ni produit ni placement ; `searchQuery` obligatoire (un texte).
     */
    case NOTE_SEARCH = 'NOTE_SEARCH';
}
