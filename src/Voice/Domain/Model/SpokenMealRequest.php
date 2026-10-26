<?php

namespace Repas\Voice\Domain\Model;

/**
 * Une demande de repas comprise (« planifie le repas hachis parmentier pour six personnes ») : le nom de la recette dit, SANS le mot « repas » ou
 * « recette » ni le « pour six personnes », et le nombre de personnes dit. Rendue par `SpokenMealPhrase::requestOf`.
 */
final readonly class SpokenMealRequest
{
    public function __construct(
        /** Le nom dit de la recette, vide quand l'utilisateur n'a dit que « le repas » (rien à chercher : on donne un exemple). */
        public string $recipe,
        /** Le nombre de personnes dit (1 à 99) ; `null` : celui du compte. */
        public ?int $servings,
        /** Un nombre de personnes a été dit mais il est hors de 1 à 99 (« pour 0 », « pour 100 personnes »), jamais gardé dans le nom : on redemande combien. */
        public bool $unusableServings = false,
        /**
         * La phrase telle que dite, SANS le nombre de personnes mais AVEC le mot « repas » ou « recette » (« le repas pour chien ») : un produit dont le nom
         * commence par ce mot est cherché sous son nom entier quand l'utilisateur répond « un produit » à « C'est un produit ? ».
         */
        public ?string $original = null,
    ) {
    }
}
