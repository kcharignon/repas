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
 * - `answer()` : la réponse d'un utilisateur lié, ou `null` quand il faut ajouter un article (`AddItemIntent` avec une phrase), ce que
 *   fait le cas d'usage `AddSpokenItem`.
 *
 * Tout ce que le modèle d'interaction ne prévoit pas (autre type de requête, autre intention d'Amazon) reçoit le repli : l'exemple de
 * phrase à dire. Une séance ouverte ne se ferme que sur « stop » ou « annule ».
 */
final readonly class AlexaDialogue
{
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
        return match ($request->intent()) {
            // Un nom compris : à `AddSpokenItem`. Sinon on redemande
            AlexaIntent::ADD_ITEM => $request->phrase === null ? $this->invite() : null,
            AlexaIntent::HELP => AlexaResponse::ask(VoiceReplies::HELP, VoiceReplies::LAUNCH),
            AlexaIntent::STOP, AlexaIntent::CANCEL => AlexaResponse::say(VoiceReplies::GOODBYE),
            AlexaIntent::FALLBACK, null => AlexaResponse::ask(VoiceReplies::FALLBACK, VoiceReplies::LAUNCH),
        };
    }

    private function invite(): AlexaResponse
    {
        return AlexaResponse::ask(VoiceReplies::LAUNCH, VoiceReplies::LAUNCH);
    }
}
