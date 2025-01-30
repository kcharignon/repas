<?php

namespace Repas\Repas\Application\GetAllRecipesByAuthorAndType;


readonly class GetAllRecipesByAuthorAndTypeQuery
{
    public function __construct(
        public string $authorId,
        public string $typeSlug,
    ) {
    }
}
