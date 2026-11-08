<?php

namespace Repas\Repas\Application\Query\ListDepartments;

use OpenApi\Attributes as OA;

/**
 * Un rayon tel que la liste des rayons le montre à un utilisateur : le rayon (global) et le type de liste de courses auquel CET utilisateur
 * l'a rattaché (ADR 0036), avec le nombre d'ingrédients de CE compte dans le rayon. Les autres lectures d'un rayon (`DepartmentDTO`) ne portent pas ce réglage de compte.
 */
final readonly class DepartmentListItemDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant du rayon (slug)', example: 'fruits-et-legumes')]
        public string $slug,
        #[OA\Property(example: 'Fruits et légumes')]
        public string $name,
        #[OA\Property(description: 'Chemin ou URL de l\'image du rayon', example: 'images/department/fruits.png')]
        public string $image,
        #[OA\Property(description: "Nom de l'icône du rayon (le front la dessine), `null` quand le rayon n'en a pas : elle distingue deux ingrédients de même nom dans deux rayons. Clé toujours écrite ; les valeurs permises sont celles du corps de `PUT /departments/{slug}` ; une icône que le front ne connaît pas s'ignore", type: 'string', example: 'snowflake', nullable: true)]
        public ?string $icon,
        #[OA\Property(description: "Identifiant du type de liste de courses auquel l'utilisateur connecté a rattaché ce rayon (ADR 0036), `null` quand le rayon n'a pas de type (ses produits vont dans la liste principale) ; propre à son compte. Clé toujours écrite", type: 'string', nullable: true)]
        public ?string $listTypeId,
        #[OA\Property(description: "Nombre d'ingrédients que l'utilisateur connecté voit dans ce rayon (≥ 0), exactement ceux de `GET /departments/{slug}/ingredients` : les siens seulement (jamais ceux d'un autre compte, administrateur compris), sous-ingrédients (parties) compris. Le front s'en sert pour cacher les rayons vides de l'écran des rayons ; la liste rend toujours TOUS les rayons. Clé toujours écrite", type: 'integer', example: 12)]
        public int $ingredientCount,
    ) {
    }
}
