<?php

namespace Repas\Repas\Infrastructure\Http\Api\CreateRecipe;


use OpenApi\Attributes as OA;
use Repas\Repas\Domain\Model\RecipeComponent;
use Repas\Repas\Infrastructure\Http\PayloadMessages;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une recette utilisée par la recette du corps (`components` de `RecipePayload`, ADR 0040 décision 24). La position est l'ordre du tableau.
 */
final readonly class RecipeComponentPayload
{
    public function __construct(
        #[Assert\NotBlank(message: PayloadMessages::COMPONENT_RECIPE)]
        #[Assert\Length(max: 36, maxMessage: PayloadMessages::COMPONENT_RECIPE)]
        #[OA\Property(description: "Identifiant de la recette utilisée : une recette du MÊME auteur que la recette écrite (celle d'un autre compte, ou une recette secrète d'un autre, est inconnue : `404`)", example: '0b1f5f0e-7a5c-4c7e-9f4b-2c1d0a9b8e77')]
        public string $recipeId,
        // `mixed` : la valeur reçue est jugée telle quelle (un nombre JSON, rien d'autre) ; un type `float` ferait convertir un texte par PHP avant tout contrôle
        #[Assert\Sequentially([
            new Assert\NotNull(message: PayloadMessages::COMPONENT_MULTIPLIER),
            new Assert\Type(type: ['int', 'float'], message: PayloadMessages::COMPONENT_MULTIPLIER),
            new Assert\Range(min: RecipeComponent::MIN_MULTIPLIER, max: RecipeComponent::MAX_MULTIPLIER, notInRangeMessage: PayloadMessages::COMPONENT_MULTIPLIER),
        ])]
        #[OA\Property(description: "Combien de fois la recette utilisée, TELLE QU'ELLE EST ÉCRITE (quel que soit son nombre de personnes) : de 0,01 à 100. Une pâte écrite pour 6 avec 250 g de farine, utilisée 2 fois, apporte 500 g de farine à la recette pour ses personnes d'écriture", type: 'number', example: 2, minimum: 0.01, maximum: 100)]
        public mixed $multiplier,
    ) {
    }
}
