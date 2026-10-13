<?php

namespace Repas\Repas\Application\Query\GetIngredient;

use Repas\Repas\Application\Loader\IngredientLoader;
use Repas\Repas\Domain\Exception\IngredientException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetIngredientQueryHandler
{
    public function __construct(
        private IngredientLoader $ingredientLoader,
    ) {
    }

    /**
     * @throws IngredientException
     */
    public function __invoke(GetIngredientQuery $query): IngredientDTO
    {
        return $this->ingredientLoader->get($query->slug, $query->viewerId, $query->viewerIsAdmin);
    }
}
