<?php

namespace Repas\Repas\Application\GetAllRecipeByAuthor;


readonly class GetAllRecipeByAuthorQuery
{

    public function __construct(
        public string $authorId
    ) {
    }
}
