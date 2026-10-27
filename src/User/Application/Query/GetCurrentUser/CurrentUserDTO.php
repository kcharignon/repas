<?php

namespace Repas\User\Application\Query\GetCurrentUser;

use OpenApi\Attributes as OA;

final readonly class CurrentUserDTO
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'utilisateur (UUID)', example: '0b7a6f0e-5c1d-4c52-9f0e-3f3c1d8b2a11')]
        public string $id,
        #[OA\Property(example: 'alexiane@example.com')]
        public string $email,
        #[OA\Property(type: 'array', items: new OA\Items(type: 'string'), example: ['ROLE_USER'])]
        public array $roles,
        #[OA\Property(description: 'Vrai si l\'utilisateur a le rôle ROLE_ADMIN', example: false)]
        public bool $isAdmin,
        #[OA\Property(description: 'Nombre de personnes proposé par défaut pour une recette', example: 4)]
        public int $defaultServing,
        #[OA\Property(description: "Mise à jour de l'application Android (DRV-637) : dernière version publiée, minimale, et si celle de ce compte est à mettre à jour")]
        public AppUpdateDTO $appUpdate,
    ) {
    }
}
