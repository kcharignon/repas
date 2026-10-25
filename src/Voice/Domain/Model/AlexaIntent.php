<?php

namespace Repas\Voice\Domain\Model;


/**
 * Les intentions du skill (ADR 0029, `docs/voice/skill/interaction-model.fr-FR.json`) que le webhook sait traiter, avec le nom que le
 * modèle d'interaction leur donne. `DocsVoiceSkillTest` impose l'égalité des deux listes : une intention ajoutée au modèle sans être
 * gérée ici (ou l'inverse) fait échouer le test.
 *
 * Les sept suivantes (de `ChooseOptionIntent` à `AMAZON.NoIntent`) servent au dialogue d'un produit inconnu (décision 11) : Alexa choisit l'intention SANS connaître notre état, donc
 * `SpokenAnswerInterpreter` interprète toujours la réponse d'après l'état de la séance, pas d'après l'intention seule.
 */
enum AlexaIntent: string
{
    /** « ajoute du pain » : le créneau `item` porte la phrase. */
    case ADD_ITEM = 'AddItemIntent';
    /** « le deuxième », « la première » : le créneau `rank` porte le rang (1 à 3) parmi les propositions. */
    case CHOOSE_OPTION = 'ChooseOptionIntent';
    /** « un nouveau produit », « aucun des trois » : aucune proposition ne convient. */
    case CREATE_ITEM = 'CreateItemIntent';
    /** « fruits et légumes » : le créneau `department` porte le rayon. */
    case ANSWER_DEPARTMENT = 'AnswerDepartmentIntent';
    /** « pièces », « kilos » : le créneau `unit` porte l'unité. */
    case ANSWER_UNIT = 'AnswerUnitIntent';
    /** « lesquels ? », « la liste », « je ne sais pas » : pendant le dialogue, lire les rayons ou les unités possibles (sans créneau). */
    case LIST_CHOICES = 'ListChoicesIntent';
    case YES = 'AMAZON.YesIntent';
    case NO = 'AMAZON.NoIntent';
    case HELP = 'AMAZON.HelpIntent';
    case STOP = 'AMAZON.StopIntent';
    case CANCEL = 'AMAZON.CancelIntent';
    case FALLBACK = 'AMAZON.FallbackIntent';
}
