<?php

namespace Repas\Voice\Domain\Model;

/**
 * Ce que le dialogue en cours cherche (ADR 0029 décision 13, VOX-305) : un produit de la liste (le dialogue d'un produit inconnu, décision 11) ou un
 * repas, c'est-à-dire une recette de l'utilisateur. Les deux recherches ne se mélangent jamais : c'est l'utilisateur qui aiguille d'une cible à l'autre.
 * Écrit dans l'état de la séance seulement pour un repas : l'état d'un produit ne change pas.
 */
enum SpokenTarget: string
{
    case ITEM = 'item';
    case MEAL = 'meal';
}
