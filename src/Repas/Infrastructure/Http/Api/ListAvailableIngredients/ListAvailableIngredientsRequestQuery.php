<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListAvailableIngredients;


use Symfony\Component\Validator\Constraints as Assert;

final readonly class ListAvailableIngredientsRequestQuery
{
    public function __construct(
        #[Assert\Length(max: 100)]
        public ?string $q = null,
    ) {
    }
}
