<?php

namespace Repas\Repas\Application\Command\ChooseIngredientList;


readonly class ChooseIngredientListCommand
{
    /** Le produit va dans la liste de son rayon (ADR 0036 décision 3) : le choix fait sur le produit est effacé. */
    public const string DEFAULT = 'default';

    /** Le produit va dans la liste principale, même si son rayon est dans un type. */
    public const string MAIN = 'main';

    /**
     * @param string $destination `default`, `main`, ou l'identifiant d'un type de liste de l'utilisateur
     * @param string $userId      le propriétaire du produit (jamais un passe-droit d'administrateur)
     */
    public function __construct(
        public string $ingredientSlug,
        public string $destination,
        public string $userId,
    ) {
    }
}
