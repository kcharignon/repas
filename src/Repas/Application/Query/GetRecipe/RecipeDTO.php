<?php

namespace Repas\Repas\Application\Query\GetRecipe;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

final readonly class RecipeDTO
{
    /**
     * @param list<RecipeRowDTO> $rows
     */
    public function __construct(
        #[OA\Property(description: 'Identifiant de la recette', example: '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34')]
        public string $id,
        #[OA\Property(example: 'pâtes carbonara')]
        public string $name,
        #[OA\Property(description: 'Nombre de personnes', example: 4)]
        public int $serving,
        #[OA\Property(description: 'Identifiant du type de recette (slug)', example: 'plat')]
        public string $typeSlug,
        #[OA\Property(example: 'plat')]
        public string $typeName,
        #[OA\Property(description: "Recette secrète : seul son auteur la voit (elle n'est pas dans « Recettes des autres » et personne d'autre ne la lit ni ne la copie). Toujours écrit ; toujours `false` pour la recette d'un autre, qu'on ne lit que si elle ne l'est pas", example: false)]
        public bool $secret,
        #[OA\Property(description: "Recette mise en favoris par son auteur (liste « Favoris », `PUT /recipes/{id}/favorite`). Toujours écrit ; toujours `false` pour la recette d'un autre : le choix est celui de l'auteur", example: false)]
        public bool $favorite,
        #[OA\Property(description: 'Ingrédients de la recette, dans l\'ordre de la recette (celui de la saisie)', type: 'array', items: new OA\Items(ref: new Model(type: RecipeRowDTO::class)))]
        public array $rows,
        #[OA\Property(description: 'Version de la recette, aussi renvoyée dans l\'en-tête `ETag` : à renvoyer dans `If-Match` pour modifier ou supprimer sans écraser le travail d\'un autre', example: 3)]
        public int $version,
        #[OA\Property(description: "L'adresse de la page Marmiton dont la recette vient (ADR 0037 décision 10), reprise par chacune de ses copies, copies de copies comprises ; `null` pour toute autre recette. Toujours écrit. Le front en fait un bouton « Voir sur Marmiton » (texte et icône de lien externe, ouvert dans un nouvel onglet avec `rel=\"noopener noreferrer\"`) seulement pour une adresse de la forme `https://www.marmiton.org/recettes/recette_{nom}_{id}.aspx`. Jamais écrite par un corps de requête", nullable: true, example: 'https://www.marmiton.org/recettes/recette_tarte-au-citron-meringuee_22082.aspx')]
        public ?string $sourceUrl,
        #[OA\Property(description: "L'identifiant de la recette dont celle-ci est la copie, si cette recette existe encore et que l'utilisateur la voit ; `null` pour une recette originale, ou dont l'original a été supprimé (ou est devenu secret). Toujours écrit : le front en tire un lien vers l'original", nullable: true, example: '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34')]
        public ?string $originalId,
        #[OA\Property(description: "Vrai si l'utilisateur modifie (`PUT /recipes/{id}`) cette recette : la sienne, ou n'importe quelle recette qu'il lit s'il est administrateur - dont une recette SANS PROPRIÉTAIRE (la neutre d'une recette Marmiton, que lui seul modifie et supprime). Faux pour la recette d'un autre. Toujours écrit, calculé sans requête de plus", example: true)]
        public bool $canEdit,
    ) {
    }
}
