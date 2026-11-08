<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListUsableRecipes;


use Symfony\Component\Validator\Constraints as Assert;

final readonly class ListUsableRecipesRequestQuery
{
    public function __construct(
        #[Assert\Length(max: 36)]
        public ?string $forRecipeId = null,
        public bool $secret = false,
        #[Assert\Length(max: 100)]
        public ?string $q = null,
    ) {
    }
}
