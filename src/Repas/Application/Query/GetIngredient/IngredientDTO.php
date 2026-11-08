<?php

namespace Repas\Repas\Application\Query\GetIngredient;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

final readonly class IngredientDTO
{
    public function __construct(
        #[OA\Property(description: 'Identifiant de l\'ingrédient (slug)', example: 'oeuf')]
        public string $slug,
        #[OA\Property(example: 'œuf')]
        public string $name,
        #[OA\Property(description: 'Chemin ou URL de l\'image (peut être vide)', example: 'images/ingredient/egg.png')]
        public string $image,
        #[OA\Property(description: 'Identifiant du rayon (slug)', example: 'divers')]
        public string $departmentSlug,
        #[OA\Property(example: 'divers')]
        public string $departmentName,
        #[OA\Property(description: 'Identifiant de l\'unité des recettes (slug)', example: 'unite')]
        public string $cookingUnitSlug,
        #[OA\Property(example: 'unité')]
        public string $cookingUnitName,
        #[OA\Property(description: 'Identifiant de l\'unité d\'achat (slug)', example: 'unite')]
        public string $purchaseUnitSlug,
        #[OA\Property(example: 'unité')]
        public string $purchaseUnitName,
        #[OA\Property(description: 'Symbole de l\'unité d\'achat (peut être vide)', example: '')]
        public string $purchaseUnitSymbol,
        #[OA\Property(description: 'Les unités des recettes et d\'achat sont les mêmes (aucune conversion)')]
        public bool $hasSameUnit,
        #[OA\Property(description: 'Combien d\'unités des recettes contient une unité d\'achat ; null si les unités sont les mêmes ou sans conversion connue', example: 250, nullable: true)]
        public ?float $coefficient,
        #[OA\Property(description: 'Identifiant du propriétaire de l\'ingrédient (le lecteur)', nullable: true)]
        public ?string $creatorId,
        #[OA\Property(description: 'Toujours vrai : l\'ingrédient est à l\'utilisateur. Le champ reste pour les clients existants')]
        public bool $canEdit,
        #[OA\Property(description: 'Nombre de recettes de l\'utilisateur qui utilisent cet ingrédient (« Utilisé dans 7 recettes · 2 listes »), toujours renseigné ; 0 si aucune, ou si l\'ingrédient est à quelqu\'un d\'autre', example: 7)]
        public int $recipeCount,
        #[OA\Property(description: 'Nombre de listes de courses de l\'utilisateur qui utilisent cet ingrédient (une ligne ou un ingrédient ajouté à la main), toujours renseigné ; 0 si aucune, ou si l\'ingrédient est à quelqu\'un d\'autre', example: 2)]
        public int $listCount,
        #[OA\Property(description: 'Marqueur « ingrédient de base » : l\'ingrédient est copié aux nouveaux comptes. Toujours écrit, jamais `null` ; vrai seulement pour le propriétaire du catalogue qui lit un ingrédient de son catalogue, faux pour tout autre lecteur')]
        public bool $isBase,
        #[OA\Property(description: 'Le lecteur peut poser le marqueur « de base » sur cet ingrédient : il est le propriétaire du catalogue et l\'ingrédient est à lui. Toujours écrit, jamais `null` : le front s\'appuie sur ce champ, pas sur le rôle administrateur')]
        public bool $canSetBase,
        #[OA\Property(description: "La liste de courses où va ce produit (ADR 0036) : le choix de l'utilisateur sur le produit (« produit à part »). Clé toujours écrite ; un compte sans type de liste lit toujours `DEFAULT`")]
        public ListDestinationDTO $listDestination,
        /** @var list<IngredientPartDTO> */
        #[OA\Property(description: "Les PARTIES de l'ingrédient (ADR 0038 : « Jaune d'œuf » et « Blanc d'œuf » pour « Œuf »), triées par nom puis identifiant ; `[]` pour un ingrédient sans partie. Clé toujours écrite. Une partie n'est jamais une ligne de la liste de courses : elle se plie dans son parent", type: 'array', items: new OA\Items(ref: new Model(type: IngredientPartDTO::class)))]
        public array $parts,
        #[OA\Property(description: "Le parent de l'ingrédient quand c'est une PARTIE d'un autre ; `null` pour un ingrédient ordinaire. Clé toujours écrite")]
        public ?IngredientPartOfDTO $partOf,
    ) {
    }
}
