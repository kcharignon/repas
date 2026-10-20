<?php

namespace Repas\Repas\Application\Query\ListMergeCandidates;

use Repas\Repas\Application\Loader\IngredientMergeLoader;
use Repas\Repas\Domain\Exception\IngredientException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListMergeCandidatesQueryHandler
{
    public function __construct(
        private IngredientMergeLoader $mergeLoader,
    ) {
    }

    /**
     * @return list<MergeCandidateDTO>
     * @throws IngredientException l'ingrédient est inconnu ou à un autre utilisateur (404), ou commun (422)
     */
    public function __invoke(ListMergeCandidatesQuery $query): array
    {
        return $this->mergeLoader->candidates($query->userId, $query->slug, $query->search);
    }
}
