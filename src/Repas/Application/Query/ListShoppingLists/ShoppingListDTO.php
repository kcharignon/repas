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
        #[OA\Property(description: 'Statut de la liste', enum: ['ACTIVE', 'PAUSED', 'SENT', 'COMPLETED'], example: 'ACTIVE')]
        public string $status,
        #[OA\Property(description: 'La liste est en cours (au plus une par utilisateur)', example: true)]
        public bool $isActive,
        #[OA\Property(description: 'La liste est en pause', example: false)]
        public bool $isPaused,
        #[OA\Property(description: 'La liste est terminée', example: false)]
        public bool $isCompleted,
        #[OA\Property(description: 'La liste est envoyée au drive : elle n\'est plus modifiable (statut `SENT`)', example: false)]
        public bool $isSent,
        #[OA\Property(description: 'Date d\'envoi au drive (ATOM), null si la liste n\'est pas envoyée', example: null, nullable: true)]
        public ?string $sentAt,
        #[OA\Property(description: 'Date de création (ATOM)', example: '2026-10-12T10:00:00+02:00')]
        public string $createdAt,
    ) {
    }
}
