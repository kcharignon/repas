<?php

namespace Repas\Repas\Application\Query\ListShoppingLists;

use OpenApi\Attributes as OA;

final readonly class ShoppingListDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de la liste', example: '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10')]
        public string $id,
        #[OA\Property(description: 'Nom saisi par l\'utilisateur, ou à défaut la date de création en français', example: 'Courses du week-end')]
        public string $displayName,
        #[OA\Property(description: 'Statut de la liste', enum: ['ACTIVE', 'PAUSED', 'COMPLETED'], example: 'ACTIVE')]
        public string $status,
        #[OA\Property(description: 'La liste est en cours (au plus une par utilisateur)')]
        public bool $isActive,
        #[OA\Property(description: 'La liste est en pause')]
        public bool $isPaused,
        #[OA\Property(description: 'La liste est terminée')]
        public bool $isCompleted,
        #[OA\Property(description: 'Date de création (ATOM)', example: '2026-10-12T10:00:00+02:00')]
        public string $createdAt,
    ) {
    }
}
