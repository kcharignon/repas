<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateIngredient;


use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps d'un ingrédient, pour la création (`POST`) comme pour le remplacement complet (`PUT`). L'image n'est pas
 * modifiable par l'API : elle reste celle de l'ingrédient.
 */
final readonly class IngredientPayload
{
    public function __construct(
        #[Assert\NotBlank]
        // 200 et non 255 : le slug d'un ingrédient personnel ou adopté est le nom suivi de l'identifiant de l'utilisateur (36 caractères) et
        // doit tenir dans les 255 de la colonne, avec la marge d'un suffixe « -2 » d'adoption (ADR 0028)
        #[Assert\Length(max: 200)]
        #[OA\Property(description: "Nom de l'ingrédient (200 caractères au plus)", example: 'Praliné', maxLength: 200)]
        public string $name,
        #[Assert\NotBlank]
        #[OA\Property(description: 'Slug du rayon', example: 'fruit-et-legume')]
        public string $departmentSlug,
        #[Assert\NotBlank]
        #[OA\Property(description: "Slug de l'unité utilisée dans les recettes", example: 'gramme')]
        public string $defaultCookingUnitSlug,
        #[Assert\NotBlank]
        #[OA\Property(description: "Slug de l'unité d'achat", example: 'kilo')]
        public string $defaultPurchaseUnitSlug,
        #[Assert\Positive]
        #[OA\Property(description: "Une unité d'achat vaut ce nombre d'unités de recette ; obligatoire quand les deux unités diffèrent et qu'aucune conversion ne les relie déjà", example: 1000, nullable: true)]
        public ?float $coefficient = null,
        #[OA\Property(description: "Marqueur « ingrédient de base » : l'ingrédient est copié aux nouveaux comptes. Facultatif : absent ou `null`, il reste inchangé (`PUT`) ou faux (`POST`). Seul le propriétaire du catalogue peut le changer, sur un ingrédient à lui : tout autre compte reçoit `403` `INGREDIENT_BASE_FORBIDDEN` quand la valeur envoyée diffère de l'actuelle", example: false, nullable: true)]
        public ?bool $isBase = null,
    ) {
    }
}
