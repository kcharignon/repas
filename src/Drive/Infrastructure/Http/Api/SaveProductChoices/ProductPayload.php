<?php

namespace Repas\Drive\Infrastructure\Http\Api\SaveProductChoices;


use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\SaveProductChoices\PackSizeChange;
use Repas\Drive\Application\Command\SaveProductChoices\ProductInput;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\RetailerProfile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un produit tel que l'agent l'a lu chez l'enseigne, avec son prix quand il l'a lu (décision D22).
 */
final readonly class ProductPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[OA\Property(description: "Identifiant du produit chez l'enseigne : 1 à 12 chiffres, en texte", pattern: RetailerProfile::NUMERIC_PRODUCT_ID, example: '123456')]
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
        #[Assert\Valid]
        #[OA\Property(
            description: "La contenance du produit dans l'unité de la liste de courses (DRV-652), pour `ADD_PRODUCT` seulement : combien d'unités de la liste (20 tranches) tiennent dans ce produit, répondu par la personne quand l'unité de la liste n'a pas de dimension (tranche, cuillère…) et que le contenu net ne permet pas de calculer le nombre de produits. Privée à l'utilisateur et propre à CE produit dans sa préférence : changer de produit ne la reprend jamais. L'unité doit exister, sinon `422` `DRIVE_PACK_SIZE_INVALID` (comme une valeur hors de 0,001 à 9 999 ou avec plus de trois décimales), et rien n'est enregistré. **Clé absente : la contenance déjà mémorisée pour ce produit est gardée** (une application qui ne connaît pas ce champ n'efface rien, un produit nouveau n'en a pas ; le `default: null` que le générateur écrit ici est un artefact, une clé absente n'est pas `null`). **`null` : elle est effacée.** Un objet : elle est remplacée. Sur une autre action, la mentionner (objet ou `null`) est `422` `DRIVE_CHOICE_INVALID`",
            nullable: true,
        )]
        public ?PackSizePayload $packSize = null,
        #[Assert\Length(max: Product::OFFER_ID_MAX_LENGTH)]
        #[OA\Property(description: "Identifiant de l'offre du produit dans le magasin (DRV-721), pour les enseignes dont un produit a deux identifiants : de la forme `offerIdPattern` du contrat des enseignes, 40 caractères au plus. Facultatif : absent ou `null`, la fiche n'est pas modifiée ; une valeur reçue remplace celle de la fiche (l'offre peut changer). Pour une enseigne sans offre (`offerIdPattern` à `null`), toute valeur est refusée : `422` `DRIVE_CHOICE_INVALID`", maxLength: 40, example: '0b5f8a2e-3c1d-4e7f-9a6b-123456789abc', nullable: true)]
        public ?string $offerId = null,
    ) {
    }

    /**
     * @param bool $mentionsPackSize vrai si le corps de la requête porte la clé `packSize` pour ce produit, même à `null` : absente de
     *                               la charge utile et `null` ne se distinguent pas, c'est le corps brut qui le dit
     *
     * @throws DriveException `packSizeInvalid()` (422) pour une contenance hors des bornes
     */
    public function toInput(bool $mentionsPackSize = false): ProductInput
    {
        $packSizeChange = match (true) {
            !$mentionsPackSize => null,
            $this->packSize === null => PackSizeChange::clear(),
            default => PackSizeChange::set($this->packSize->toPackSize()),
        };

        return new ProductInput($this->retailerProductId, $this->label, $this->ean, $this->content?->toContent(), $this->price?->toPrice(), $this->rayonId, $this->familyId, $packSizeChange, $this->offerId);
    }
}
