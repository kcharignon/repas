<?php

namespace Repas\Voice\Application\Query\GetSpokenItemInList;


use Repas\Voice\Application\Port\ListLineLookupQueryPort;
use Repas\Voice\Domain\Service\SpokenListAnswerer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Répond à « est-ce qu'il y a des croquettes dans la liste ? » (ADR 0029 décision 12, VOX-304) : une lecture, qui ne modifie rien et ne crée jamais de
 * liste. La liste est lue par `ListLineLookupQueryPort` ; les cinq réponses possibles sont décrites par `SpokenListAnswerer`, que le dialogue d'une
 * question en cours utilise aussi.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetSpokenItemInListQueryHandler
{
    public function __construct(
        private ListLineLookupQueryPort $lookup,
        private SpokenListAnswerer $answerer,
    ) {
    }

    public function __invoke(GetSpokenItemInListQuery $query): SpokenItemInListDTO
    {
        $phrase = $this->answerer->phraseToLookUp($query->phrase);

        return new SpokenItemInListDTO($this->answerer->speech($this->lookup->find($query->userId, $phrase), $phrase), $query->sessionIsOpen);
    }
}
