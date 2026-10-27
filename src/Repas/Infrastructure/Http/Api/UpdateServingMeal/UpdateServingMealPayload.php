<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateServingMeal;


use OpenApi\Attributes as OA;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Infrastructure\Http\PayloadMessages;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateServingMealPayload
{
    public function __construct(
        // `mixed` : la valeur reçue est jugée telle quelle (un entier JSON, rien d'autre) ; un type `int` ferait convertir une décimale par PHP avant tout contrôle
        #[Assert\Sequentially([
            new Assert\NotNull(message: PayloadMessages::SERVING),
            new Assert\Type(type: 'integer', message: PayloadMessages::SERVING),
            new Assert\Range(min: Meal::MIN_SERVING, max: Meal::MAX_SERVING, notInRangeMessage: PayloadMessages::SERVING),
        ])]
        #[OA\Property(description: 'Nombre de personnes du repas, de 1 à 100', type: 'integer', example: 6, minimum: 1, maximum: 100)]
        public mixed $serving,
    ) {
    }
}
