<?php

namespace Repas\User\Application\Query\ListUsers;

use OpenApi\Attributes as OA;

final readonly class UserSummaryDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'utilisateur', example: '396c20ff-eda7-472f-9555-3e247824b0b6')]
        public string $id,
        #[OA\Property(example: 'prenom.nom@example.com')]
        public string $email,
        #[OA\Property(description: 'Nombre de personnes habituel', example: 4)]
        public int $defaultServing,
        #[OA\Property(description: 'Nombre de recettes créées', example: 73)]
        public int $recipeCount,
        #[OA\Property(description: 'Nombre d\'ingrédients créés', example: 15)]
        public int $ingredientCount,
        #[OA\Property(description: 'Nombre de listes de courses suivies', example: 3)]
        public int $shoppingListCount,
        #[OA\Property(description: 'Date d\'inscription (ATOM), vide si inconnue', example: '2026-10-13T08:56:40+02:00')]
        public string $createdAt,
        #[OA\Property(description: 'Le compte est désactivé')]
        public bool $isDisabled,
        #[OA\Property(description: 'L\'utilisateur est administrateur')]
        public bool $isAdmin,
        #[OA\Property(description: 'État du compte : `PENDING` (adresse email pas encore confirmée), `ACTIVE` ou `DISABLED`', enum: ['PENDING', 'ACTIVE', 'DISABLED'], example: 'ACTIVE')]
        public string $status,
        #[OA\Property(description: "Dernière version de l'application Android vue pour ce compte (DRV-637), `null` si inconnue (navigateur seul, application qui ne l'a jamais envoyée)", type: 'string', example: '0.1.8', nullable: true)]
        public ?string $appVersion,
        #[OA\Property(description: "« À mettre à jour » : vrai si `appVersion` est plus ancienne que la dernière version publiée (`APP_LATEST_VERSION`) ou que la minimale (`APP_MIN_VERSION`) ; faux si la version est inconnue ou si le serveur ne connaît aucune des deux", example: false)]
        public bool $appOutdated,
    ) {
    }
}
