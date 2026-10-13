<?php

namespace Repas\Repas\Application\Query\GetIngredient;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetIngredientQuery implements QueryInterface
{
    public function __construct(
        public string $slug,
        public string $viewerId,
        public bool $viewerIsAdmin,
    ) {
    }
}
