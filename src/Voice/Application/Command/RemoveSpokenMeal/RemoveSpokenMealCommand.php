<?php

namespace Repas\Voice\Application\Command\RemoveSpokenMeal;


/**
 * L'utilisateur vient de dire qu'il retire un repas (« enlève le repas hachis parmentier », « retire la recette … pour 2 personnes ») : trouver le repas
 * de sa liste active qui le porte et l'enlever, ou en baisser le nombre de personnes (ADR 0029 décision 12, VOX-306). `userId` vient du jeton d'accès
 * résolu, jamais du corps de la requête ; la phrase est celle du créneau `recipe` (ou de `item`), déjà débarrassée du mot « repas » / « recette » et du
 * « pour 2 personnes » (`SpokenMealPhrase`).
 */
final readonly class RemoveSpokenMealCommand
{
    public function __construct(
        public string $userId,
        public string $recipePhrase,
        /** La séance était déjà ouverte : la réponse la laisse ouverte, sans rien redemander. Sinon (appel direct), elle se ferme. */
        public bool $sessionIsOpen,
        /** Le nombre de personnes ENLEVÉ (1 à 99, déjà vérifié) ; `null` : le repas entier. */
        public ?int $servings = null,
        /** Les produits ont déjà été cherchés pour cette phrase (l'utilisateur a répondu « un repas ») : « ou un produit ? » n'est plus proposé. */
        public bool $otherSideTried = false,
        /**
         * La phrase telle que dite, avec le mot « repas » ou « recette » (`SpokenMealRequest::$original`) : un produit dont le nom commence par ce mot est
         * cherché sous son nom entier quand l'utilisateur répond « un produit ». `null` : la phrase de la recette suffit.
         */
        public ?string $originalPhrase = null,
    ) {
    }
}
