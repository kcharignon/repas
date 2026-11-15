<?php

namespace Repas\Voice\Application\Query\GetVoiceTrace;


use Repas\Shared\Application\Interface\QueryInterface;

/**
 * Un échange avec Alexa, en entier, d'après son numéro (ADR 0029 décision 14) : `repas:voice:traces --id=N`. Lecture seule.
 */
final readonly class GetVoiceTraceQuery implements QueryInterface
{
    public function __construct(
        public int $id,
    ) {
    }
}
