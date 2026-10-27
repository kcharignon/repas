<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateServingMeal;


use OpenApi\Attributes as OA;
use Repas\Repas\Domain\Model\Meal;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateServingMealPayload
{
    public function __construct(
        #[Assert\Range(min: Meal::MIN_SERVING, max: Meal::MAX_SERVING)]
        #[OA\Property(description: 'Nombre de personnes du repas, de 1 à 100', example: 6, minimum: 1, maximum: 100)]
        public int $serving,
    ) {
    }
}
