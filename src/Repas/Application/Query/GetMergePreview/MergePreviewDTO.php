<?php

namespace Repas\Repas\Application\Query\GetMergePreview;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Ce que la fusion de deux ingrédients de l'utilisateur ferait, sans rien écrire (ADR 0028 décision 9, `docs/ingredients/fusion.md` § 7) :
 * le même verdict que l'écriture, calculé par le même service de domaine sur les mêmes faits.
 */
final readonly class MergePreviewDTO
{
    /**
     * @param list<MergeUnitDTO> $missingUnits
     */
    public function __construct(
        #[OA\Property(ref: new Model(type: MergeIngredientDTO::class), description: 'L\'ingrédient gardé : il garde son nom, son image, son rayon, ses unités et ses conversions')]
        public MergeIngredientDTO $keep,
        #[OA\Property(ref: new Model(type: MergeIngredientDTO::class), description: 'L\'ingrédient absorbé : il disparaît, tout ce qui l\'utilise passe sur le gardé')]
        public MergeIngredientDTO $absorbed,
        #[OA\Property(description: 'Nombre de recettes de l\'utilisateur qui utilisent l\'ingrédient absorbé (elles passent sur le gardé). Ce n\'est PAS le total d\'après la fusion : voir resultRecipeCount', example: 7)]
        public int $recipeCount,
        #[OA\Property(description: 'Nombre de listes de courses de l\'utilisateur qui utilisent l\'ingrédient absorbé (elles passent sur le gardé). Ce n\'est PAS le total d\'après la fusion : voir resultListCount', example: 2)]
        public int $listCount,
        #[OA\Property(description: 'Nombre de choix du drive de l\'ingrédient absorbé qui passent sur le gardé, toutes enseignes et tous magasins ; pas ceux qui sont supprimés parce que le gardé a déjà le sien dans le même magasin', example: 1)]
        public int $driveChoiceCount,
        #[OA\Property(description: 'Le rayon de l\'ingrédient absorbé n\'est pas celui du gardé : le rayon du gardé l\'emporte')]
        public bool $departmentChanges,
        #[OA\Property(description: 'Des quantités de listes de courses sont ré-exprimées dans l\'unité d\'achat de l\'ingrédient gardé (les unités d\'achat diffèrent) ; les quantités des recettes ne changent jamais')]
        public bool $listQuantitiesConverted,
        #[OA\Property(description: 'Les unités de l\'ingrédient absorbé que l\'ingrédient gardé ne sait pas convertir en son unité d\'achat, MÊME APRÈS avoir récupéré les conversions de l\'absorbé (recoveredConversionCount) : une vraie impossibilité ; vide si rien ne manque. Renseignée même quand un autre refus passe avant', type: 'array', items: new OA\Items(ref: new Model(type: MergeUnitDTO::class)))]
        public array $missingUnits,
        #[OA\Property(
            description: 'Pourquoi la fusion serait refusée (le premier cas dans l\'ordre de la liste), null si elle est possible. SAME_INGREDIENT : les deux ingrédients sont le même. COMMON_INGREDIENT : l\'un est un ingrédient commun. USED_BY_ANOTHER_USER : une ligne d\'un autre utilisateur utilise l\'ingrédient absorbé. IN_SENT_LIST : une liste envoyée au drive utilise l\'un des deux. UNIT_MISSING : voir missingUnits',
            type: 'string',
            enum: ['SAME_INGREDIENT', 'COMMON_INGREDIENT', 'USED_BY_ANOTHER_USER', 'IN_SENT_LIST', 'UNIT_MISSING'],
            nullable: true,
        )]
        public ?string $blockedBy,
        #[OA\Property(description: 'La fusion est possible : blockedBy est null')]
        public bool $canMerge,
        #[OA\Property(description: 'Nombre de recettes DISTINCTES de l\'utilisateur qui utiliseront l\'ingrédient gardé APRÈS la fusion : celles qui l\'utilisent déjà, plus celles de l\'ingrédient absorbé ; une recette qui utilisait déjà les deux compte une fois. C\'est le total à annoncer ("sera utilisé dans N recettes"), alors que recipeCount ne compte que ce qui se déplace. Toujours renseigné (0 quand les deux ingrédients sont le même ou qu\'un commun est en jeu : rien n\'est lu)', example: 8)]
        public int $resultRecipeCount,
        #[OA\Property(description: 'Nombre de listes de courses DISTINCTES de l\'utilisateur qui utiliseront l\'ingrédient gardé APRÈS la fusion (une ligne ou un ingrédient ajouté à la main ; une liste qui avait déjà les deux compte une fois). Toujours renseigné, comme resultRecipeCount', example: 3)]
        public int $resultListCount,
        #[OA\Property(description: 'Nombre de conversions de l\'ingrédient absorbé que l\'ingrédient gardé n\'avait pas et qui lui sont copiées par la fusion : celles dont les deux unités ne sont pas encore reliées chez lui ; si elles le sont, celles du gardé gagnent et rien n\'est copié. Toujours 0 quand la fusion est refusée (canMerge faux) : rien n\'est écrit', example: 1)]
        public int $recoveredConversionCount,
    ) {
    }
}
