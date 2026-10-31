<?php

namespace Repas\Drive\Application\OpenHostService;

/**
 * Empreinte publiée des choix de produits d'un utilisateur, vus sans le slug d'ingrédient qu'ils portent (voir `IngredientChoiceFingerprint`).
 * Aucun nom, aucun e-mail : des identifiants de choix (jamais affichés), des nombres et une somme de contrôle.
 */
final readonly class IngredientChoiceFingerprintDTO
{
    /**
     * @param int $choices nombre de choix de l'utilisateur, toutes enseignes et tous magasins
     * @param int $products nombre de lignes de produits rattachées (acceptés et refusés)
     * @param string $packSizeSum somme des contenances mémorisées, en texte décimal exact
     * @param string $contentHash somme de contrôle de tout ce qui n'est PAS le slug
     * @param array<string, string> $ingredientSlugByChoice le slug d'ingrédient de chaque choix, par identifiant de choix
     * @param array<string, int> $productCountByChoice le nombre de lignes de produits de chaque choix, par identifiant de choix
     */
    public function __construct(
        public int $choices,
        public int $products,
        public string $packSizeSum,
        public string $contentHash,
        public array $ingredientSlugByChoice,
        public array $productCountByChoice,
    ) {
    }
}
