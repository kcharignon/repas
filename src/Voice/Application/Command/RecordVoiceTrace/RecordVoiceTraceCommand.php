<?php

namespace Repas\Voice\Application\Command\RecordVoiceTrace;


use Repas\Voice\Domain\Model\AlexaResponse;
use Repas\Voice\Domain\Model\TracedFailure;
use Repas\Voice\Domain\Model\TraceOutcome;

/**
 * Un échange avec Alexa vient d'être répondu : le garder dans la trace (ADR 0029 décision 14). Le corps est celui d'une requête DÉJÀ prouvée
 * authentique ; le handler l'assainit par liste blanche avant d'écrire quoi que ce soit.
 */
final readonly class RecordVoiceTraceCommand
{
    public function __construct(
        /** Le compte de la liaison, `null` quand aucun jeton n'a été résolu. */
        public ?string $userId,
        public ?string $linkId,
        /** Le corps JSON décodé de la requête d'Alexa, tel qu'Amazon l'a envoyé. */
        public mixed $body,
        /** La réponse vocale rendue à Alexa. */
        public AlexaResponse $response,
        public TraceOutcome $outcome,
        /** La décision prise (le cas d'usage lancé), `null` quand aucun ne l'a été. */
        public ?string $decision,
        public int $durationMs,
        public ?TracedFailure $failure = null,
    ) {
    }
}
