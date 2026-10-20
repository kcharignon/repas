<?php

namespace Repas\Repas\Domain\Model;


/**
 * Le degré de ressemblance de deux noms d'ingrédients (`IngredientNameMatcher`, ADR 0028 décision 8 et 10).
 */
enum MatchLevel: string
{
    /** Les deux noms sont identiques une fois normalisés (casse, accents, ponctuation, pluriel, mots vides) : certain. */
    case EXACT = 'exact';

    /** À une faute de frappe près, Jaro-Winkler de 0,97 au moins : assez sûr pour brancher sans demander. */
    case TYPO = 'typo';

    /** De 0,90 à 0,97 : assez proche pour PROPOSER à l'utilisateur (« Doublons possibles »), jamais pour brancher seul. */
    case SUGGESTION = 'suggestion';

    /** Deux ingrédients différents (ou un nom sans lettre ni chiffre, qui ne ressemble à rien). */
    case NONE = 'none';
}
