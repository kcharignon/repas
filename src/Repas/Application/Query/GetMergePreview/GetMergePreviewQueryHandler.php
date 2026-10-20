<?php

namespace Repas\Repas\Application\Query\GetMergePreview;

use Repas\Repas\Application\Loader\IngredientMergeLoader;
use Repas\Repas\Domain\Exception\IngredientException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetMergePreviewQueryHandler
{
    public function __construct(
        private IngredientMergeLoader $mergeLoader,
    ) {
    }

    /**
     * @throws IngredientException un des deux ingrédients est inconnu ou à un autre utilisateur
     */
    public function __invoke(GetMergePreviewQuery $query): MergePreviewDTO
    {
        return $this->mergeLoader->preview($query->userId, $query->absorbedSlug, $query->keptSlug);
    }
}
