<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateServingMeal;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateServingMealPayload
{
    public function __construct(
        #[Assert\Range(min: 1, max: 100)]
        #[OA\Property(description: 'Nombre de personnes du repas', example: 6)]
        public int $serving,
    ) {
    }
}
