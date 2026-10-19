<?php

namespace Repas\Drive\Infrastructure\Http\Api\SaveProductChoices;


use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\SaveProductChoices\ProductInput;
use Repas\Drive\Domain\Model\Product;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un produit tel que l'agent l'a lu chez l'enseigne, avec son prix quand il l'a lu (décision D22).
 */
final readonly class ProductPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[OA\Property(description: "Identifiant du produit chez l'enseigne : 1 à 12 chiffres, en texte", pattern: '^[0-9]{1,12}$', example: '123456')]
        public string $retailerProductId,
        #[Assert\Length(max: 255)]
        #[OA\Property(description: 'Libellé du produit (255 caractères au plus, sans caractère de contrôle ni caractère invisible ou de format : saut de ligne, sens de lecture, largeur nulle, trait d\'union conditionnel, sélecteur de variation, remplisseur, tag ; les espaces insécables sont permises)', example: 'Farine de blé T55 Marque Repère 1 kg', nullable: true)]
        public ?string $label = null,
        #[OA\Property(description: 'Code-barres, 8 à 14 chiffres', pattern: '^[0-9]{8,14}$', example: '3017620422003', nullable: true)]
        public ?string $ean = null,
        #[Assert\Valid]
        #[OA\Property(description: 'Contenu net, `null` si l\'agent n\'a pas su le lire', nullable: true)]
        public ?ProductContentPayload $content = null,
        #[Assert\Valid]
        #[OA\Property(description: 'Prix lu par l\'agent, `null` s\'il ne l\'a pas lu', nullable: true)]
        public ?ProductPricePayload $price = null,
        #[Assert\Range(min: 1, max: Product::CATALOG_ID_MAX)]
        #[OA\Property(description: "Rayon de l'enseigne où le site range le produit (`iIdRayon` chez Leclerc) : entier de 1 à 2147483647. Facultatif : absent ou `null`, la fiche du produit n'est pas modifiée ; une valeur déjà connue n'est jamais remplacée. Sert à apprendre dans quel rayon de l'enseigne chercher les ingrédients d'un rayon de Repas (`rayonHints` du plan)", minimum: 1, maximum: 2147483647, example: 4001, nullable: true)]
        public ?int $rayonId = null,
        #[Assert\Range(min: 1, max: Product::CATALOG_ID_MAX)]
        #[OA\Property(description: "Famille de l'enseigne, le niveau sous le rayon (`iIdFamille` chez Leclerc) : entier de 1 à 2147483647. Facultatif, mêmes règles que `rayonId`", minimum: 1, maximum: 2147483647, example: 4101, nullable: true)]
        public ?int $familyId = null,
    ) {
    }

    public function toInput(): ProductInput
    {
        return new ProductInput($this->retailerProductId, $this->label, $this->ean, $this->content?->toContent(), $this->price?->toPrice(), $this->rayonId, $this->familyId);
    }
}
