<?php

namespace Repas\Voice\Application\Query\ListVoiceTraces;


use DateTimeImmutable;

/**
 * Un échange avec Alexa en une ligne, tel que `repas:voice:traces` l'écrit (ADR 0029 décision 14). Le texte dit par Alexa et le résumé des créneaux
 * sont précalculés par le chargeur. Ce que l'utilisateur a dit peut y figurer : cela ne sort que par la console, jamais par une route HTTP.
 */
final readonly class VoiceTraceSummaryDTO
{
    public function __construct(
        public int $id,
        public DateTimeImmutable $createdAt,
        public string $requestType,
        public ?string $intent,
        public ?bool $sessionNew,
        /** Une valeur de `TraceOutcome`. */
        public string $outcome,
        public ?string $decision,
        public int $durationMs,
        /** Ce qu'Alexa a répondu (`response.outputSpeech.text`), `null` quand elle n'a rien dit. */
        public ?string $said,
        /** Les créneaux entendus, résumés : `item="du pain", department="divers" (non reconnu)`, `null` sans créneau. */
        public ?string $heard,
        /** L'étape du dialogue que la séance portait à l'arrivée de la requête (`choose`, `department`…), `null` sans dialogue. */
        public ?string $dialogueStep,
        /** La classe de l'exception d'une panne, `null` sans panne. */
        public ?string $failureClass,
    ) {
    }
}
