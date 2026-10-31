<?php

namespace Repas\Repas\Infrastructure\Http\Api\ChooseIngredientList;


use OpenApi\Attributes as OA;
use Repas\Repas\Infrastructure\Http\PayloadMessages;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps du réglage de la liste d'un produit (« produit à part »).
 */
final readonly class ChooseIngredientListPayload
{
    public function __construct(
        #[Assert\NotBlank(message: PayloadMessages::LIST_DESTINATION)]
        #[Assert\Length(max: 36, maxMessage: PayloadMessages::LIST_DESTINATION)]
        #[OA\Property(description: "Où va le produit : `default` (dans la liste de son rayon, le choix est effacé), `main` (dans la liste principale, même si son rayon est dans un type) ou l'identifiant d'un type de liste du compte", example: 'main', maxLength: 36)]
        public string $destination,
    ) {
    }
}
