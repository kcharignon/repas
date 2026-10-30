<?php

namespace Repas\Drive\Infrastructure\Http\Api\SaveProductChoices;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceIntent;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\Retailer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Lot d'intentions issues du résultat de l'agent : l'utilisateur vient du jeton, jamais du corps. Les valeurs (identifiants,
 * libellé, contenu, prix) sont contrôlées par le domaine (`DRIVE_CHOICE_INVALID`).
 */
final readonly class SaveProductChoicesPayload
{
    /**
     * @param list<ChoiceIntentPayload> $choices
     */
    public function __construct(
        #[OA\Property(description: 'Enseigne du drive', enum: Retailer::IDS, example: 'leclerc')]
        public Retailer $retailer,
        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        #[OA\Property(description: 'Magasin des produits : doit être le magasin courant des réglages de l\'utilisateur', example: '123456')]
        public string $storeId,
        #[Assert\Count(max: 200)]
        #[Assert\Valid]
        #[OA\Property(description: 'Intentions, 200 au plus, appliquées dans l\'ordre reçu pour un même ingrédient', type: 'array', items: new OA\Items(ref: new Model(type: ChoiceIntentPayload::class)))]
        public array $choices,
    ) {
    }

    /**
     * Les intentions du lot. La clé `searchQuery` d'une intention (ou `packSize` de son produit, DRV-652) absente du corps (garder la
     * valeur mémorisée) ne se distingue pas de `null` (l'effacer) dans la charge utile : on regarde le corps brut, intention par
     * intention (même clé que dans le lot).
     *
     * @param array<array-key, mixed> $rawChoices le tableau `choices` du corps de la requête, tel que reçu
     *
     * @return list<ChoiceIntent>
     *
     * @throws DriveException `searchQueryInvalid()` ou `packSizeInvalid()` (422) pour une valeur hors des bornes
     */
    public function toIntents(array $rawChoices): array
    {
        $intents = [];
        foreach ($this->choices as $key => $choice) {
            $raw = $rawChoices[$key] ?? null;
            $rawProduct = is_array($raw) ? ($raw['product'] ?? null) : null;
            $intents[] = $choice->toIntent(
                is_array($raw) && array_key_exists('searchQuery', $raw),
                is_array($rawProduct) && array_key_exists('packSize', $rawProduct),
            );
        }

        return $intents;
    }
}
