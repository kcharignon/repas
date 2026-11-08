<?php

namespace Repas\Repas\Application\Query\GetRecipe;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Une recette que la recette lue UTILISE (ADR 0040 décision 19) : « Pâte sablée, 2 fois ». Ses lignes sont celles de la recette utilisée TELLES
 * QU'ELLES SONT ÉCRITES, jamais multipliées : c'est au front de calculer `quantité × multiplicateur × personnes ÷ personnes de la recette lue`,
 * comme il met déjà à l'échelle les lignes propres.
 */
final readonly class RecipeComponentDTO
{
    /**
     * @param list<RecipeRowDTO> $rows
     */
    public function __construct(
        #[OA\Property(description: 'Identifiant de la recette utilisée', example: '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34')]
        public string $recipeId,
        #[OA\Property(description: 'Nom de la recette utilisée', example: 'Pâte sablée')]
        public string $name,
        #[OA\Property(description: "Nombre de personnes de la recette utilisée, pour lequel ses lignes sont écrites. Ne sert qu'à l'affichage : le multiplicateur ne dépend pas de lui", example: 6)]
        public int $serving,
        #[OA\Property(description: "Combien de fois la recette utilisée, TELLE QU'ELLE EST ÉCRITE (de 0,01 à 100). Une pâte écrite pour 6 avec 250 g de farine, utilisée 2 fois, apporte 500 g de farine à la recette lue pour son nombre de personnes ; le nombre de personnes de la recette lue met le tout à l'échelle", example: 2.0)]
        public float $multiplier,
        #[OA\Property(description: 'La recette utilisée est secrète. Toujours écrit ; une recette ordinaire n\'utilise que des recettes ordinaires, donc faux pour la recette d\'un autre', example: false)]
        public bool $secret,
        #[OA\Property(description: "Toutes les lignes de la recette utilisée se convertissent en l'unité d'achat de leur ingrédient (comme `convertible` d'une ligne) ; faux : la recette lue ne peut pas être planifiée tant qu'une conversion manque", example: true)]
        public bool $convertible,
        #[OA\Property(description: "Les lignes de la recette utilisée, dans l'ordre de cette recette, TELLES QU'ELLES SONT ÉCRITES (pour son nombre de personnes, jamais multipliées par le multiplicateur)", type: 'array', items: new OA\Items(ref: new Model(type: RecipeRowDTO::class)))]
        public array $rows,
    ) {
    }
}
