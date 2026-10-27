<?php

namespace Repas\Repas\Application\Query\GetConversionHealth;

/**
 * Ce qu'un compte possède de non convertible. Le compte n'est désigné que par son rang (par ordre d'identifiant) et sa référence courte, les 8
 * premiers caractères du SHA-256 de son identifiant : jamais son adresse ni son identifiant, jamais le nom d'un ingrédient ou d'une recette.
 */
final readonly class ConversionHealthAccountDTO
{
    /**
     * @param int $rank le rang du compte, à partir de 1
     * @param string $reference les 8 premiers caractères du SHA-256 de l'identifiant (de quoi le désigner sans le dévoiler)
     * @param string $status `ACTIVE`, `PENDING` ou `DISABLED`
     * @param int $ingredients les ingrédients du compte
     * @param int $ingredientsNotConvertible ceux dont l'unité des recettes ne se convertit pas en l'unité d'achat
     * @param int $recipes les recettes du compte
     * @param int $recipeRows les lignes de ses recettes
     * @param int $recipeRowsNotConvertible celles dont l'unité ne se convertit pas en l'unité d'achat de leur ingrédient (le `convertible` de la lecture de la recette)
     */
    public function __construct(
        public int $rank,
        public string $reference,
        public string $status,
        public int $ingredients,
        public int $ingredientsNotConvertible,
        public int $recipes,
        public int $recipeRows,
        public int $recipeRowsNotConvertible,
    ) {
    }
}
