<?php

namespace Repas\Drive\Infrastructure\Http\Api\SetDefaultRetailer;


use OpenApi\Attributes as OA;
use Repas\Drive\Domain\Model\Retailer;

/**
 * Corps du choix du drive habituel. La clé `retailer` est **obligatoire** : `null` efface le drive habituel, mais un corps sans la clé est
 * refusé (`422`), il n'efface pas par oubli. Par défaut le désérialiseur donne `null` à une clé absente d'un paramètre qui accepte `null` :
 * le controller demande donc `require_all_properties` (`SetDefaultRetailerApiController`).
 */
#[OA\Schema(required: ['retailer'])]
final readonly class SetDefaultRetailerPayload
{
    public function __construct(
        #[OA\Property(description: "Le drive habituel : une enseigne allumée où l'utilisateur a enregistré un magasin ; `null` pour ne plus en avoir. La clé est obligatoire", enum: Retailer::IDS, example: 'leclerc', nullable: true)]
        public ?Retailer $retailer,
    ) {
    }
}
