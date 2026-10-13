<?php

namespace Repas\Repas\Application\Query\ListRecipesByType;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListRecipesByTypeQuery implements QueryInterface
{
    public function __construct(
        public string $authorId,
        public string $typeSlug,
    ) {
    }
}
