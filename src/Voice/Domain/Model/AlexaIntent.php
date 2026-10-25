<?php

namespace Repas\Voice\Domain\Model;


/**
 * Les intentions du skill (ADR 0029, `docs/voice/skill/interaction-model.fr-FR.json`) que le webhook sait traiter, avec le nom que le
 * modèle d'interaction leur donne. `DocsVoiceSkillTest` impose l'égalité des deux listes : une intention ajoutée au modèle sans être
 * gérée ici (ou l'inverse) fait échouer le test.
 */
enum AlexaIntent: string
{
    /** « ajoute du pain » : le créneau `item` porte la phrase. */
    case ADD_ITEM = 'AddItemIntent';
    case HELP = 'AMAZON.HelpIntent';
    case STOP = 'AMAZON.StopIntent';
    case CANCEL = 'AMAZON.CancelIntent';
    case FALLBACK = 'AMAZON.FallbackIntent';
}
