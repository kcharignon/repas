<?php

namespace Repas\Repas\Domain\Model;


/**
 * Un ingrédient que le classement d'une phrase prononcée peut retenir (`SpokenIngredientRanker`) : une valeur légère, lue sans hydrater
 * de modèle. Toujours un ingrédient de l'utilisateur qui parle (ADR 0028) ; les références sont des slugs (ADR 0013).
 */
final readonly class SpokenCandidate
{
    /**
     * @param int $uses12m le nombre de fois où l'ingrédient est dans les listes et les recettes de l'utilisateur sur douze mois (l'habitude)
     */
    public function __construct(
        public string $slug,
        public string $sluggedName,
        public string $name,
        public string $departmentName,
        public int $uses12m = 0,
    ) {
    }
}
