<?php

namespace Repas\Repas\Domain\Model;


/**
 * Deux ingrédients d'un même utilisateur, du même rayon, que l'application pense être le même (`DuplicateIngredientFinder`, ING-006).
 * `first` est celui dont le slug précède l'autre (ordre des octets) : l'ordre ne dit rien de celui qu'on garderait, c'est
 * `suggestedKeep()`.
 */
final readonly class DuplicatePair
{
    public function __construct(
        public IngredientCandidate $first,
        public IngredientCandidate $second,
        public NameSimilarity $similarity,
    ) {
    }

    /**
     * Celui des deux qu'on garde par défaut quand on les fusionne : l'écran le présélectionne (« À garder »), la personne peut basculer.
     * Règle, dans l'ordre, la première qui départage : (1) le plus de recettes qui l'utilisent ; (2) le plus de listes de courses ; (3) le
     * nom le plus court (« Tomate » avant « Tomates ») ; (4) l'ordre alphabétique du nom normalisé ; (5) l'ordre des slugs. Le résultat
     * ne dépend donc jamais de l'ordre d'arrivée des lectures.
     */
    public function suggestedKeep(int $firstRecipes, int $firstLists, int $secondRecipes, int $secondLists): IngredientCandidate
    {
        $order = [$secondRecipes, $secondLists, mb_strlen($this->first->name), $this->first->normalizedName(), $this->first->slug]
            <=> [$firstRecipes, $firstLists, mb_strlen($this->second->name), $this->second->normalizedName(), $this->second->slug];

        return $order <= 0 ? $this->first : $this->second;
    }
}
