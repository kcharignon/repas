<?php

namespace Repas\Voice\Domain\Service;


use Repas\Voice\Domain\Model\AlexaIntent;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\AlexaResponse;
use Repas\Voice\Domain\Model\VoiceReplies;

/**
 * La conversation d'Alexa avec Repas, pour tout ce qui ne demande ni la liste ni les ingrédients de l'utilisateur (ADR 0029 décision 2,
 * VOX-204). Pure : une requête décodée entre, une réponse sort, rien n'est lu ni écrit.
 *
 * Deux temps, dans l'ordre où le webhook les pose :
 *
 * - `beforeAuthentication()` : ce qu'on peut répondre sans savoir qui parle (la fin d'une séance ne reçoit aucune parole ; une autre
 *   langue que le français reçoit un repli poli) ;
 * - `answer()` : la réponse d'un utilisateur lié, ou `null` quand un cas d'usage doit lire ou écrire : ajouter un article
 *   (`AddItemIntent` avec une phrase : `AddSpokenItem`), le retirer (`RemoveItemIntent` : `RemoveSpokenItem`), demander s'il est dans la liste
 *   (`CheckItemIntent` : la lecture `GetSpokenItemInList`, ADR 0029 décision 12), ou répondre à une question du dialogue d'un produit inconnu
 *   (la séance porte un état : `AnswerSpokenDialogue`, qui interprète la réponse d'après l'ÉTAT, `SpokenAnswerInterpreter`), ou planifier un repas
 *   (`AddMealIntent`, ou `AddItemIntent` dont la phrase commence par « repas » ou « recette » : `AddSpokenMeal`, ADR 0029 décision 13). Seuls « stop » et
 *   « annule » sont traités ici dans tous les états. Un retrait ou une consultation sans produit reçoivent un exemple, jamais une question ouverte :
 *   la réponse brève (« du pain ») serait comprise comme un ajout.
 *
 * Tout ce que le modèle d'interaction ne prévoit pas (autre type de requête, autre intention d'Amazon) reçoit le repli : l'exemple de
 * phrase à dire. Une séance ouverte ne se ferme que sur « stop » ou « annule ».
 */
final readonly class AlexaDialogue
{
    private SpokenMealPhrase $meals;

    public function __construct(?SpokenMealPhrase $meals = null)
    {
        $this->meals = $meals ?? new SpokenMealPhrase();
    }

    public function beforeAuthentication(AlexaRequest $request): ?AlexaResponse
    {
        if ($request->type === AlexaRequest::SESSION_ENDED) {
            return AlexaResponse::silence();
        }

        // Une langue absente ou autre que le français : Alexa n'enverrait pas ce qu'on attend, on ne devine pas
        return $request->hasSupportedLocale() ? null : AlexaResponse::say(VoiceReplies::UNSUPPORTED_LANGUAGE);
    }

    public function answer(AlexaRequest $request): ?AlexaResponse
    {
        return match ($request->type) {
            AlexaRequest::LAUNCH => $this->invite(),
            AlexaRequest::INTENT => $this->answerIntent($request),
            default => AlexaResponse::say(VoiceReplies::FALLBACK),
        };
    }

    private function answerIntent(AlexaRequest $request): ?AlexaResponse
    {
        $intent = $request->intent();

        // « Stop » et « annule » ferment la séance dans tous les états, même au milieu d'un dialogue
        if ($intent === AlexaIntent::STOP || $intent === AlexaIntent::CANCEL) {
            return AlexaResponse::say(VoiceReplies::GOODBYE);
        }

        // Une question est posée : la réponse dépend de l'état, pas de l'intention seule
        if ($request->dialogueState !== null) {
            return null;
        }

        // Un repas à planifier (ADR 0029 décision 13) : le mot « repas » ou « recette », ou l'intention des repas, aiguille avant l'intention des produits. Une
        // recette comprise et un nombre de personnes écrivable : à `AddSpokenMeal`. Sinon un exemple, jamais une question ouverte (la réponse brève serait
        // comprise comme un produit), ou on redemande combien
        $meal = $this->meals->requestOf($request);
        if ($meal !== null) {
            return match (true) {
                $meal->recipe === '' => AlexaResponse::reply(VoiceReplies::MEAL_EXAMPLE, $request->isInOpenSession(), null),
                $request->hasUnusableServings() => AlexaResponse::reply(VoiceReplies::HOW_MANY, $request->isInOpenSession(), null),
                default => null,
            };
        }

        return match ($intent) {
            // Un nom compris : à `AddSpokenItem`. Sinon on redemande ; une quantité que nous ne savons pas écrire (zéro, plus de 99, un
            // décimal) : rien n'est écrit, on redemande combien
            AlexaIntent::ADD_ITEM => match (true) {
                $request->phrase === null => $this->invite(),
                $request->hasUnusableQuantity() => AlexaResponse::ask(VoiceReplies::HOW_MANY, VoiceReplies::LAUNCH),
                default => null,
            },
            // Retirer : un produit compris et une quantité écrivable, sinon l'exemple (séance fermée sauf si elle était ouverte : jamais de question)
            AlexaIntent::REMOVE_ITEM => match (true) {
                $request->phrase === null => AlexaResponse::reply(VoiceReplies::REMOVE_EXAMPLE, $request->isInOpenSession(), null),
                $request->hasUnusableQuantity() => AlexaResponse::reply(VoiceReplies::HOW_MANY, $request->isInOpenSession(), null),
                default => null,
            },
            // Consulter : un produit compris, sinon l'exemple
            AlexaIntent::CHECK_ITEM => $request->phrase === null ? AlexaResponse::reply(VoiceReplies::CHECK_EXAMPLE, $request->isInOpenSession(), null) : null,
            // « Oui » à rien : on redemande ce qu'il veut ajouter ; « non » : la réponse à rien, on dit au revoir
            AlexaIntent::YES => $this->invite(),
            AlexaIntent::NO => AlexaResponse::say(VoiceReplies::GOODBYE),
            AlexaIntent::HELP => AlexaResponse::ask(VoiceReplies::HELP, VoiceReplies::LAUNCH),
            // Le repli : le repli d'Amazon, une intention qu'il n'a pas déclarée, et les réponses de dialogue (un rang, un nouveau produit, un
            // rayon, une unité, « lesquels ? » de `ListChoicesIntent`) sans dialogue en cours — séance trop vieille, état perdu. « Stop » et « annule » sont traités plus haut.
            default => AlexaResponse::ask(VoiceReplies::FALLBACK, VoiceReplies::LAUNCH),
        };
    }

    private function invite(): AlexaResponse
    {
        return AlexaResponse::ask(VoiceReplies::LAUNCH, VoiceReplies::LAUNCH);
    }
}
