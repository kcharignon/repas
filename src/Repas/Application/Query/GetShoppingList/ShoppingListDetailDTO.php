<?php

namespace Repas\Repas\Application\Query\GetShoppingList;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

final readonly class ShoppingListDetailDTO
{
    /**
     * @param list<ShoppingListDepartmentDTO> $departments
     * @param list<ShoppingListRecipeTypeDTO> $recipeTypes
     */
    public function __construct(
        #[OA\Property(description: 'Identifiant de la liste', example: '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10')]
        public string $id,
        #[OA\Property(description: 'Nom saisi par l\'utilisateur, null s\'il n\'en a pas donné', example: 'Courses du week-end', nullable: true)]
        public ?string $name,
        #[OA\Property(description: 'Nom saisi, sinon « Liste du » suivi de la date de création en français', example: 'Liste du 12 octobre 2026')]
        public string $displayName,
        #[OA\Property(description: 'Date de création en français', example: '12 octobre 2026')]
        public string $dateLabel,
        #[OA\Property(description: 'Date de création (ATOM)', example: '2026-10-12T10:00:00+02:00')]
        public string $createdAt,
        #[OA\Property(description: 'Statut de la liste', enum: ['ACTIVE', 'PAUSED', 'COMPLETED'], example: 'ACTIVE')]
        public string $status,
        #[OA\Property(example: true)]
        public bool $isActive,
        #[OA\Property(example: false)]
        public bool $isPaused,
        #[OA\Property(example: false)]
        public bool $isCompleted,
        #[OA\Property(description: 'Nombre de lignes d\'ingrédient', example: 12)]
        public int $rowCount,
        #[OA\Property(description: 'Nombre de recettes planifiées', example: 3)]
        public int $recipeCount,
        #[OA\Property(description: 'Ingrédients groupés par rayon (triés par slug)', type: 'array', items: new OA\Items(ref: new Model(type: ShoppingListDepartmentDTO::class)))]
        public array $departments,
        #[OA\Property(description: 'Recettes planifiées groupées par type (triées par ordre d\'affichage)', type: 'array', items: new OA\Items(ref: new Model(type: ShoppingListRecipeTypeDTO::class)))]
        public array $recipeTypes,
        #[OA\Property(description: 'Version de la liste, aussi renvoyée dans l\'en-tête `ETag` : à renvoyer dans `If-Match` pour modifier la liste sans écraser le travail d\'un autre (cocher une ligne ne la change pas)', example: 3)]
        public int $version,
    ) {
    }
}
