<?php

namespace Repas\Repas\Infrastructure\Http\Api\SearchIngredients;


use Symfony\Component\Validator\Constraints as Assert;

final readonly class SearchIngredientsRequestQuery
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 100)]
        public string $q,
    ) {
    }
}
