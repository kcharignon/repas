<?php

namespace Repas\Drive\Application\Query\ListChoices;

use JsonSerializable;
use OpenApi\Attributes as OA;

final readonly class ChoiceProductDTO implements JsonSerializable
{
    public function __construct(
        #[OA\Property(description: 'Identifiant Repas du produit', example: '0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10')]
        public string $productId,
        #[OA\Property(description: "Identifiant du produit chez l'enseigne (chiffres)", example: '123456')]
        public string $retailerProductId,
        #[OA\Property(description: 'Code-barres, si connu', example: '3017620422003', nullable: true)]
        public ?string $ean,
        #[OA\Property(description: 'Libellé du produit', example: 'Farine de blé T55 Marque Repère 1 kg')]
        public string $label,
        #[OA\Property(description: 'Contenu net, `null` si inconnu', nullable: true)]
        public ?ProductContentDTO $content,
        #[OA\Property(description: "Combien d'unités de la liste tiennent dans ce produit, tel que la personne l'a répondu (DRV-652) : « 20 tranches » pour un cheddar que la liste compte en tranches. Propre à l'utilisateur et à ce produit dans cette préférence ; `null` si elle n'a rien répondu (et toujours pour un produit refusé). Elle ne sert au calcul que pour la même unité : si la liste change d'unité, elle est ignorée", nullable: true)]
        public ?PackSizeDTO $packSize,
        #[OA\Property(description: "Identifiant de l'offre du produit dans le magasin (DRV-721), chez les enseignes dont un produit a deux identifiants. **La clé est absente** (jamais `null`) quand le produit n'a pas d'offre (toute enseigne sans offre, ou offre pas encore rapportée par un agent)", example: '0b5f8a2e-3c1d-4e7f-9a6b-123456789abc', nullable: false)]
        public ?string $offerId = null,
    ) {
    }

    /**
     * La clé `offerId` n'est écrite que si le produit a une offre : la réponse d'une enseigne sans offre reste identique à ce qu'elle était
     * avant DRV-721, octet pour octet.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $properties = get_object_vars($this);
        if ($this->offerId === null) {
            unset($properties['offerId']);
        }

        return $properties;
    }
}
