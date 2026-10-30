<?php

namespace Repas\Drive\Infrastructure\Http\Api\ReplaceIngredientPreference;


use OpenApi\Attributes as OA;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\Store;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nouvelle préférence d'un ingrédient, depuis l'écran de gestion des produits : l'utilisateur vient du jeton, jamais du corps.
 * Les bornes (5 produits acceptés, 20 refusés), les doublons et les identifiants inconnus sont contrôlés par le domaine
 * (`DRIVE_CHOICE_INVALID`).
 */
final readonly class ReplaceIngredientPreferencePayload
{
    /**
     * @param list<string> $accepted
     * @param list<string> $rejected
     */
    public function __construct(
        #[OA\Property(description: 'Enseigne du drive', enum: Retailer::IDS, example: 'leclerc')]
        public Retailer $retailer,
        #[Assert\NotBlank]
        #[Assert\Length(max: Store::ID_MAX_LENGTH)]
        #[OA\Property(description: "Magasin des produits : doit être le magasin courant des réglages de l'utilisateur", example: '123456')]
        public string $storeId,
        #[OA\Property(description: "Vrai pour « j'en ai toujours » : `accepted` et `rejected` doivent alors être vides", example: false)]
        public bool $haveAtHome,
        #[Assert\All([new Assert\Type('string'), new Assert\Length(min: 1, max: 36)])]
        #[OA\Property(description: 'Identifiants Repas des produits acceptés (`productId` de `GET /drive/choices`), le préféré d\'abord ; 5 au plus', type: 'array', items: new OA\Items(type: 'string', example: '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10'))]
        public array $accepted,
        #[Assert\All([new Assert\Type('string'), new Assert\Length(min: 1, max: 36)])]
        #[OA\Property(description: 'Identifiants Repas des produits à ne jamais prendre ; 20 au plus', type: 'array', items: new OA\Items(type: 'string', example: '5d1c0e0a-3f21-4c6e-8c0f-5a9b3a7b6d11'))]
        public array $rejected,
    ) {
    }
}
