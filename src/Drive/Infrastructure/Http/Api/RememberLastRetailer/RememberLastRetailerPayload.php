<?php

namespace Repas\Drive\Infrastructure\Http\Api\RememberLastRetailer;


use OpenApi\Attributes as OA;
use Repas\Drive\Domain\Model\Retailer;

final readonly class RememberLastRetailerPayload
{
    public function __construct(
        #[OA\Property(description: "L'enseigne que l'utilisateur vient d'ouvrir : une enseigne allumée où il a enregistré un magasin", enum: Retailer::IDS, example: 'leclerc')]
        public Retailer $retailer,
    ) {
    }
}
