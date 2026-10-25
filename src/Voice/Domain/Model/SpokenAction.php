<?php

namespace Repas\Voice\Domain\Model;

/**
 * Ce que le dialogue en cours doit faire de l'article que l'utilisateur va désigner (ADR 0029 décision 12) : l'ajouter (le dialogue d'un produit
 * inconnu, décision 11) ou le retirer (départager deux lignes de la liste qui se valent). Écrit dans l'état de la séance seulement pour un retrait :
 * l'état d'un ajout ne change pas.
 */
enum SpokenAction: string
{
    case ADD = 'add';
    case REMOVE = 'remove';
}
