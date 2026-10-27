<?php

namespace Repas\Repas\Application\Query\ListIngredientConversions;

use Repas\Repas\Application\Loader\ConversionLoader;
use Repas\Repas\Domain\Exception\IngredientException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListIngredientConversionsQueryHandler
{
    public function __construct(
        private ConversionLoader $conversionLoader,
    ) {
    }

    /**
     * @throws IngredientException l'ingrédient est inconnu, ou à un autre utilisateur (un administrateur compris)
     */
    public function __invoke(ListIngredientConversionsQuery $query): IngredientConversionsDTO
    {
        return $this->conversionLoader->listOfIngredient($query->slug, $query->viewerId);
    }
}
