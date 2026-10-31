<?php

namespace Repas\Repas\Infrastructure\Http\Api\UpdateShoppingListType;


use OpenApi\Attributes as OA;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Repas\Infrastructure\Http\PayloadMessages;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps du remplacement d'un type de liste : son nom ET le jeu complet de ses rayons.
 */
final readonly class UpdateShoppingListTypePayload
{
    /**
     * @param list<string> $departmentSlugs
     */
    public function __construct(
        #[Assert\NotBlank(message: PayloadMessages::LIST_TYPE_NAME)]
        #[Assert\Length(max: ShoppingListType::MAX_NAME_LENGTH, maxMessage: PayloadMessages::LIST_TYPE_NAME)]
        #[OA\Property(description: 'Nom du type, de 1 à 40 caractères, unique par compte une fois normalisé ; sa première lettre est mise en majuscule. Renommer un type ne renomme pas ses listes', example: 'Boucherie', maxLength: ShoppingListType::MAX_NAME_LENGTH)]
        public string $name,
        #[Assert\Count(max: 200, maxMessage: PayloadMessages::LIST_TYPE_DEPARTMENTS)]
        #[Assert\All([new Assert\Type(type: 'string', message: PayloadMessages::LIST_TYPE_DEPARTMENTS), new Assert\NotBlank(message: PayloadMessages::LIST_TYPE_DEPARTMENTS), new Assert\Length(max: 255, maxMessage: PayloadMessages::LIST_TYPE_DEPARTMENTS)])]
        #[OA\Property(description: "Le jeu COMPLET des rayons du type (leurs slugs) : ceux qui n'y sont plus sont détachés, un tableau vide les détache tous. Un rayon qu'un autre type du compte avait est déplacé dans celui-ci (un rayon est dans un seul type à la fois)", type: 'array', items: new OA\Items(type: 'string', example: 'viandes'), maxItems: 200)]
        public array $departmentSlugs,
    ) {
    }
}
