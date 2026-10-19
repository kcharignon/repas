<?php

namespace Repas\User\Application\Query\GetCurrentUser;

use OpenApi\Attributes as OA;

/**
 * Invitation à mettre à jour l'application (DRV-637), calculée par le loader pour la dernière version de l'application Android
 * vue pour ce compte. Ne sert qu'à informer : jamais à bloquer ni à accorder un droit.
 */
final readonly class AppUpdateDTO
{
    public function __construct(
        #[OA\Property(description: "Dernière version publiée de l'application (`APP_LATEST_VERSION` du serveur), `null` si le serveur ne la connaît pas", type: 'string', example: '0.1.10', nullable: true)]
        public ?string $latest,
        #[OA\Property(description: "Plus ancienne version de l'application encore attendue (`APP_MIN_VERSION` du serveur), `null` si aucune", type: 'string', example: '0.1.9', nullable: true)]
        public ?string $minimum,
        #[OA\Property(description: "Vrai si la dernière version de l'application vue pour ce compte est plus ancienne que `latest` ou que `minimum` (comparaison nombre par nombre). Faux si aucune version n'est connue pour ce compte (navigateur seul, application qui ne l'a jamais envoyée) ou si le serveur ne connaît ni `latest` ni `minimum`", example: false)]
        public bool $outdated,
    ) {
    }
}
