<?php

namespace Repas\Voice\Domain\Model;

/**
 * L'étape où en est le dialogue d'un produit inconnu (ADR 0029 décision 11, VOX-303). La valeur est celle écrite dans les attributs de
 * séance : jamais changée sans migration du dialogue en cours (une séance dure quelques minutes).
 */
enum DialogueStep: string
{
    /** « Tu veux dire A, B ou C ? Ou un nouveau produit ? » (sans proposition : « Je le crée ? »). */
    case CHOOSE = 'choose';
    /** « Dans quel rayon ? » */
    case DEPARTMENT = 'department';
    /** « Quelle unité ? » */
    case UNIT = 'unit';
    /** « Je crée X, rayon, unité. C'est bon ? » */
    case CONFIRM = 'confirm';
    /** « Hachis parmentier est déjà prévu pour 4 personnes. Tu le veux pour combien ? » (un repas, décision 13). */
    case SERVINGS = 'servings';
}
