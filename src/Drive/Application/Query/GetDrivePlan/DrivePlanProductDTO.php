<?php

namespace Repas\Drive\Application\Query\GetDrivePlan;

use JsonSerializable;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Query\ListChoices\PackSizeDTO;
use Repas\Drive\Application\Query\ListChoices\ProductContentDTO;

final readonly class DrivePlanProductDTO implements JsonSerializable
{
    public function __construct(
        #[OA\Property(description: 'Rang du produit : 1 = le préféré, les suivants servent de secours', example: 1)]
        public int $rank,
        #[OA\Property(description: "Identifiant du produit chez l'enseigne (chiffres)", example: '123456')]
        public string $retailerProductId,
        #[OA\Property(description: 'Code-barres, si connu', example: '3017620422003', nullable: true)]
        public ?string $ean,
        #[OA\Property(description: 'Libellé du produit', example: 'Farine de blé T55 Marque Repère 1 kg')]
        public string $label,
        #[OA\Property(description: 'Contenu net, `null` si inconnu', nullable: true)]
        public ?ProductContentDTO $content,
        #[OA\Property(description: 'Nombre de produits à mettre au panier pour la quantité demandée (1 à 99), calculé avec la contenance mémorisée de ce produit si elle est dans l\'unité de la ligne (DRV-652), sinon avec son contenu net ; `null` quand il ne se calcule pas', minimum: 1, maximum: 99, nullable: true, example: 1)]
        public ?int $packs,
        #[OA\Property(description: '`COMPUTED` : calcul fiable ; `TO_CHECK` : valeur par défaut ou absente, l\'agent demande la quantité', enum: ['COMPUTED', 'TO_CHECK'], example: 'COMPUTED')]
        public string $packsStatus,
        #[OA\Property(description: "La contenance que la personne a donnée pour CE produit, dans l'unité de la liste (DRV-652), ou `null` si elle n'en a pas donné. Quand son `unitSlug` est celui de la ligne (`needed.unitSlug`), `packs` est calculé avec elle et vaut `COMPUTED`, sans question ; une contenance dans une autre unité est ignorée par le calcul (mais reste renvoyée)", nullable: true)]
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
