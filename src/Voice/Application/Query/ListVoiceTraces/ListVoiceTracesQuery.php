<?php

namespace Repas\Voice\Application\Query\ListVoiceTraces;


use DateTimeImmutable;
use Repas\Shared\Application\Interface\QueryInterface;

/**
 * Les derniers échanges avec Alexa, le plus récent d'abord (ADR 0029 décision 14) : `repas:voice:traces`. Lecture seule.
 */
final readonly class ListVoiceTracesQuery implements QueryInterface
{
    public function __construct(
        /** Combien d'échanges au plus (le chargeur borne de 1 à `VoiceTraceLoader::MAX_LIMIT`). */
        public int $limit = 20,
        /** Seulement cette intention (`AddItemIntent`…), `null` : toutes. */
        public ?string $intent = null,
        /** Seulement les pannes. */
        public bool $failedOnly = false,
        /** Seulement ce qui date de cet instant ou d'après, `null` : tout ce qui est gardé. */
        public ?DateTimeImmutable $since = null,
    ) {
    }
}
