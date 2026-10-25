<?php

namespace Repas\Voice\Application\Query\GetSpokenItemInList;


use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Répond à « est-ce qu'il y a des croquettes dans la liste ? » (ADR 0029 décision 12, VOX-304) : une lecture, qui ne modifie rien et ne crée jamais de
 * liste. Les cinq réponses possibles sont décrites par `SpokenListAnswerer`, que le dialogue d'une question en cours utilise aussi.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetSpokenItemInListQueryHandler
{
    public function __construct(
        private SpokenListAnswerer $answerer,
    ) {
    }

    public function __invoke(GetSpokenItemInListQuery $query): SpokenItemInListDTO
    {
        return new SpokenItemInListDTO($this->answerer->speech($query->userId, $query->phrase), $query->sessionIsOpen);
    }
}
