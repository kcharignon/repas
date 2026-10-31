<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateShoppingListType;


use OpenApi\Attributes as OA;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Repas\Infrastructure\Http\PayloadMessages;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de la création d'un type de liste : son nom, rien d'autre (les rayons se rattachent ensuite, `PUT`).
 */
final readonly class CreateShoppingListTypePayload
{
    public function __construct(
        #[Assert\NotBlank(message: PayloadMessages::LIST_TYPE_NAME)]
        #[Assert\Length(max: ShoppingListType::MAX_NAME_LENGTH, maxMessage: PayloadMessages::LIST_TYPE_NAME)]
        #[OA\Property(description: 'Nom du type, de 1 à 40 caractères, unique par compte une fois normalisé ; sa première lettre est mise en majuscule', example: 'Boucherie', maxLength: ShoppingListType::MAX_NAME_LENGTH)]
        public string $name,
    ) {
    }
}
