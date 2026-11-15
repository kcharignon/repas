<?php

namespace Repas\Voice\Application\Query\GetVoiceTrace;


use Repas\Voice\Application\Query\ListVoiceTraces\VoiceTraceSummaryDTO;

/**
 * Un échange avec Alexa en entier, tel que `repas:voice:traces --id=N` l'écrit (ADR 0029 décision 14) : le résumé, la requête assainie et la réponse
 * rendue (JSON complets) et la panne éventuelle. Aucun jeton : ce que la table garde est déjà assaini par liste blanche.
 */
final readonly class VoiceTraceDetailDTO
{
    /**
     * @param array<array-key, mixed> $request
     * @param array<array-key, mixed> $response
     * @param array<string, string>|null $failure `class`, `location`, `message` et, si l'exception en avait une, `previousClass`, `previousLocation`, `previousMessage`
     */
    public function __construct(
        public VoiceTraceSummaryDTO $summary,
        /** Le compte de la liaison, `null` quand aucun jeton n'a été résolu. */
        public ?string $userId,
        public ?string $linkId,
        public array $request,
        public array $response,
        public ?array $failure,
    ) {
    }
}
