<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateRecipe;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Infrastructure\Http\PayloadMessages;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps d'une recette, pour la création (`POST`) comme pour le remplacement complet (`PUT`).
 */
final readonly class RecipePayload
{
    /**
     * @param list<RecipeRowPayload> $rows
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: 'Nom de la recette', example: 'Pâtes carbonara')]
        public string $name,
        // `mixed` : la valeur reçue est jugée telle quelle (un entier JSON, rien d'autre) ; un type `int` ferait convertir une décimale par PHP avant tout contrôle
        #[Assert\Sequentially([
            new Assert\NotNull(message: PayloadMessages::SERVING),
            new Assert\Type(type: 'integer', message: PayloadMessages::SERVING),
            new Assert\Range(min: Recipe::MIN_SERVING, max: Recipe::MAX_SERVING, notInRangeMessage: PayloadMessages::SERVING),
        ])]
        #[OA\Property(description: 'Nombre de personnes, de 1 à 100', type: 'integer', example: 4, minimum: 1, maximum: 100)]
        public mixed $serving,
        #[Assert\NotBlank]
        #[OA\Property(description: 'Slug du type de recette', example: 'plat')]
        public string $typeSlug,
        #[Assert\Count(min: 1, max: Recipe::MAX_ROWS, maxMessage: PayloadMessages::TOO_MANY_ROWS)]
        #[Assert\Valid]
        #[OA\Property(description: 'Lignes de la recette, de une à cent', minItems: 1, maxItems: 100, type: 'array', items: new OA\Items(ref: new Model(type: RecipeRowPayload::class)))]
        public array $rows,
    ) {
    }
}
