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
     * @param list<RecipeComponentPayload>|null $components
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: 'Nom de la recette. La première lettre est mise en majuscule par le serveur (le reste du nom est conservé tel quel) : le nom enregistré, renvoyé par les lectures, peut donc différer de celui envoyé', example: 'Pâtes carbonara')]
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
        // Zéro ligne est permis par le corps : une recette peut être faite SEULEMENT de recettes utilisées (`components`). « Lignes + recettes utilisées ≥ 1 »
        // est jugé par le cas d'usage sur l'état final (`422 RECIPE_EMPTY`), de sorte qu'un ancien client qui envoie `rows: []` reçoit toujours `422`
        #[Assert\Count(max: Recipe::MAX_ROWS, maxMessage: PayloadMessages::TOO_MANY_ROWS)]
        #[Assert\Valid]
        #[OA\Property(description: "Lignes (ingrédients) de la recette, de zéro à cent. Zéro ligne n'est permis que si la recette utilise au moins une autre recette (`components`) : sinon `422` `RECIPE_EMPTY`. Ce sont les seules lignes PROPRES de la recette (jamais celles de ses recettes utilisées, que `GET /recipes/{id}` rend dans `components`)", minItems: 0, maxItems: 100, type: 'array', items: new OA\Items(ref: new Model(type: RecipeRowPayload::class)))]
        public array $rows,
        // `mixed` : la valeur reçue est jugée telle quelle (un booléen JSON, rien d'autre) ; `null` vaut « absent »
        #[Assert\Type(type: 'bool', message: PayloadMessages::SECRET)]
        #[OA\Property(description: "Recette secrète : seul l'auteur la voit, elle n'est pas dans « Recettes des autres » et personne d'autre ne la lit ni ne la copie (un administrateur non plus). Facultatif : absent ou `null`, il reste inchangé (`PUT`) ou faux (`POST`) ; un ancien client ne le remet donc jamais à zéro. Un booléen JSON, rien d'autre (`422` sinon). Seul l'auteur le change : un administrateur qui modifie la recette ordinaire d'un autre et envoie `true` reçoit `403` `RECIPE_SECRET_FORBIDDEN` (la secrète d'un autre lui est inconnue : `404`). Les copies déjà faites par d'autres restent à eux", type: 'boolean', example: false, nullable: true)]
        public mixed $secret = null,
        // `null` vaut « absent » (comme `secret`) : jamais « tout retirer », que seul `[]` dit
        #[Assert\Valid]
        #[OA\Property(
            description: "Les recettes que cette recette UTILISE (ADR 0040), jusqu'à 10, dans l'ordre où elles s'affichent (l'ordre du tableau est la position). Facultatif : **absent ou `null` = inchangé** (un ancien client qui renvoie `rows` seul n'efface jamais un composant ; à la création, aucune) ; **`[]` = tout retirer** ; sinon remplacement complet. Chaque recette utilisée est une recette du MÊME auteur que la recette écrite, sans composant elle-même (profondeur 1), et ordinaire si la recette l'est (une recette secrète peut utiliser les deux). Refus : `404` recette utilisée inconnue, d'un autre auteur ou secrète d'un autre (administrateur compris) ; `422` `RECIPE_COMPONENT_SELF`, `RECIPE_COMPONENT_DUPLICATE`, `RECIPE_COMPONENT_MULTIPLIER_INVALID`, `RECIPE_COMPONENT_LIMIT_REACHED` (plus de 10), `RECIPE_COMPONENT_CHAIN` (la recette est déjà utilisée par une autre, ou une recette utilisée a ses propres composants), `RECIPE_COMPONENT_OWNERLESS` (une recette sans propriétaire n'en reçoit jamais), `RECIPE_EMPTY` ; `409` `RECIPE_COMPONENT_SECRET` (composant secret pour une recette ordinaire). Rien n'est écrit sur un refus",
            type: 'array',
            nullable: true,
            maxItems: 10,
            items: new OA\Items(ref: new Model(type: RecipeComponentPayload::class)),
        )]
        public ?array $components = null,
    ) {
    }
}
