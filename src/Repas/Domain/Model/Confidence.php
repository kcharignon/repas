<?php

namespace Repas\Repas\Domain\Model;


/**
 * Le degré de confiance du choix d'un ingrédient pour une phrase prononcée (`SpokenIngredientRanker`, `docs/voice/alexa.md` § 4.5).
 * Il dit comment l'annoncer à l'utilisateur ; dans tous les cas l'erreur se répare d'un geste dans l'application.
 */
enum Confidence: string
{
    /** Le nom dit est celui de l'ingrédient (ou l'utilisateur l'a déjà confirmé pour cette phrase) : on ajoute sans rien ajouter. */
    case SURE = 'sure';

    /** Le premier devance nettement le suivant (écart d'au moins `SpokenIngredientRanker::CLEAR_MARGIN`) : on ajoute. */
    case PROBABLE = 'probable';

    /** Le premier et le suivant se touchent : on ajoute le premier et on le dit, pour que l'utilisateur puisse corriger. */
    case UNSURE = 'unsure';
}
