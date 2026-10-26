<?php

namespace Repas\Voice\Domain\Model;

/**
 * Ce que l'utilisateur a répondu à la question de l'étape en cours (ADR 0029 décision 11), tel que `SpokenAnswerInterpreter` l'a compris.
 */
enum SpokenAnswerKind
{
    /** Il a choisi l'une des propositions (`SpokenAnswer::$option`). */
    case PICK_OPTION;
    /** Aucune proposition ne convient, ou il accepte de créer le produit : on pose la question du rayon. */
    case START_CREATION;
    /** Il a dit un autre article (`SpokenAnswer::$phrase`) : le dialogue est abandonné, la phrase est une nouvelle demande. */
    case NEW_REQUEST;
    /** Il a dit « enlève … » (`SpokenAnswer::$phrase`) : le dialogue est abandonné, la phrase est une demande de retrait (décision 12). */
    case NEW_REMOVAL;
    /** Il a demandé « est-ce qu'il y a … ? » (`SpokenAnswer::$phrase`) : le dialogue est abandonné, la phrase est une consultation de la liste (décision 12). */
    case NEW_CHECK;
    /** Il a dit « planifie le repas … » (`SpokenAnswer::$phrase`, `$number` : les personnes dites) : le dialogue est abandonné, c'est une nouvelle demande de repas (décision 13). */
    case NEW_MEAL;
    /** Il a dit « enlève le repas … » (`SpokenAnswer::$phrase`, `$number` : les personnes enlevées) : le dialogue est abandonné, c'est une nouvelle demande de retrait d'un repas (décision 12, VOX-306). */
    case NEW_MEAL_REMOVAL;
    /** Un produit non compris : c'est une recette (« une recette », décision 13) ; on cherche la phrase d'origine dans ses recettes. */
    case SWITCH_TO_MEAL;
    /** Une recette non comprise : c'est un produit (« un produit », décision 13) ; on cherche la phrase d'origine dans ses produits. */
    case SWITCH_TO_ITEM;
    /** Le nouveau total de personnes d'un repas déjà planifié (`SpokenAnswer::$number`, décision 13). */
    case PICK_SERVINGS;
    case PICK_DEPARTMENT;
    case PICK_UNIT;
    /** Il confirme la création. */
    case CONFIRM;
    /** Il refuse : on ne crée rien. */
    case ABANDON;
    /** Il demande de l'aide : on répète la question. */
    case REPEAT;
    /** Il demande les choix possibles (« lesquels ? », ou « aide ») à l'étape du rayon ou de l'unité : on les lit, puis on repose la question. */
    case LIST_CHOICES;
    /** « Oui » alors qu'il y a plusieurs propositions : on redemande laquelle. */
    case WHICH_ONE;
    /** Rien de compréhensible pour cette étape. */
    case UNCLEAR;
}
