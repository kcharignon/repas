<?php

namespace Repas\Repas\Application\Command\SeedUserIngredients;


/**
 * Ce qu'est devenu l'amorçage d'un compte.
 */
enum SeedOutcome: string
{
    /** Le compte a reçu des ingrédients (et il est maintenant « amorcé »). */
    case SEEDED = 'seeded';
    /** Le compte n'existe pas, ou c'est le propriétaire du catalogue (ses ingrédients sont les siens) : rien n'est écrit. */
    case NOT_CONCERNED = 'not-concerned';
    /** Le compte est déjà amorcé (au moins un alias d'adoption) : rien n'est écrit, versions comprises. */
    case ALREADY_SEEDED = 'already-seeded';
    /**
     * Pas de propriétaire du catalogue, ou aucun ingrédient « de base » à copier (le classement n'est pas encore posé) : rien n'est écrit et
     * le compte reste non amorcé, donc servi par un rattrapage plus tard.
     */
    case NO_CATALOG = 'no-catalog';
    /** `complete` : le compte a déjà tout ce que le catalogue lui doit. Rien n'est écrit. */
    case NOTHING_TO_ADD = 'nothing-to-add';
}
