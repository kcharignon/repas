<?php

namespace Repas\Drive\Application\Query\GetDrivePlan;

use OpenApi\Attributes as OA;

final readonly class DrivePlanCountsDTO
{
    public function __construct(
        #[OA\Property(description: 'Lignes dont au moins un produit est connu dans le magasin (`KNOWN`)', example: 12)]
        public int $known,
        #[OA\Property(description: 'Lignes sans produit connu : il faudra en choisir un (`NEW`)', example: 3)]
        public int $new,
        #[OA\Property(description: "Lignes « j'en ai toujours », ignorées à l'envoi (`HAVE_AT_HOME`)", example: 2)]
        public int $haveAtHome,
    ) {
    }
}
