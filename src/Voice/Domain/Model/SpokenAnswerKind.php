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
