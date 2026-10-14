<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateShoppingList;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateShoppingListPayload
{
    public function __construct(
        #[Assert\Length(max: 255)]
        #[OA\Property(description: 'Nouveau nom ; absent ou null : nom par défaut (la date de création)', example: 'Courses du week-end', nullable: true)]
        public ?string $name = null,
    ) {
    }
}
