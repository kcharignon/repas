<?php

namespace Repas\Voice\Infrastructure\Http\Api\HandleAlexaRequest;


use Repas\Voice\Domain\Model\TraceOutcome;

/**
 * Ce que le webhook apprend d'un échange en le traitant et que la trace garde (ADR 0029 décision 14) : le compte et la liaison dès que le jeton est
 * résolu, la décision prise, l'issue. Un objet PAR REQUÊTE, créé par le controller et jamais gardé : le controller est un service partagé (mode
 * worker), il ne retient rien d'une requête à l'autre.
 */
final class ExchangeNotes
{
    public ?string $userId = null;
    public ?string $linkId = null;
    /** Le cas d'usage lancé (`AddSpokenItem`, `AnswerSpokenDialogue:department`…), `AlexaDialogue` quand le dialogue a répondu seul. */
    public ?string $decision = null;
    public TraceOutcome $outcome = TraceOutcome::ANSWERED;
}
