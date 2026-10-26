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
    ) {
    }
}
