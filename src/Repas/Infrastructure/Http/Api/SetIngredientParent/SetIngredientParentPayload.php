<?php

namespace Repas\Repas\Infrastructure\Http\Api\SetIngredientParent;


use OpenApi\Attributes as OA;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\QuantityBounds;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps du découpage d'un ingrédient en partie d'un autre : le parent et le ratio (l'ingrédient qu'on découpe est dans l'URL).
 */
final readonly class SetIngredientParentPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: "Slug du parent : l'ingrédient dont l'ingrédient de l'URL fait partie. À l'utilisateur lui aussi (le parent d'un autre est inconnu), et lui-même n'est pas une partie", example: 'oeuf-0a1b2c3d', maxLength: 255)]
        public string $parent,
        // `mixed` : la valeur reçue est jugée telle quelle (un nombre JSON, rien d'autre : un texte, même « 2 », est refusé sans conversion). Le
        // message est le code de l'erreur métier, que le modèle de domaine (garde finale) lève aussi pour un ratio hors bornes.
        #[Assert\Sequentially([
            new Assert\NotNull(message: IngredientException::PART_RATIO_INVALID),
            new Assert\Type(type: ['int', 'float'], message: IngredientException::PART_RATIO_INVALID),
            new Assert\Range(min: QuantityBounds::MIN_COEFFICIENT, max: QuantityBounds::MAX_COEFFICIENT, notInRangeMessage: IngredientException::PART_RATIO_INVALID),
        ])]
        #[OA\Property(description: "Combien d'unités par défaut de la partie font UNE unité de recette du parent (1 œuf = 1 jaune : 1 ; une gousse de vanille = 2 demi-gousses : 2), de 0,000001 à 1 000 000", type: 'number', example: 1, minimum: 0.000001, maximum: 1000000)]
        public mixed $ratio,
    ) {
    }
}
