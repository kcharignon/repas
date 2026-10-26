<?php

namespace Repas\Voice\Application\Command\AddSpokenMeal;


/**
 * L'utilisateur vient de dire un repas (« planifie le repas hachis parmentier ») : trouver la recette qu'il désigne parmi les siennes et la planifier
 * dans sa liste de courses (ADR 0029 décision 13, VOX-305). `userId` vient du jeton d'accès résolu, jamais du corps de la requête ; la phrase est celle
 * du créneau `recipe` (ou de `item`), déjà débarrassée du mot « repas » / « recette » et du « pour six personnes » (`SpokenMealPhrase`).
 */
final readonly class AddSpokenMealCommand
{
    public function __construct(
        public string $userId,
        public string $recipePhrase,
        /** La séance était déjà ouverte : la réponse la laisse ouverte, sans rien redemander. Sinon (appel direct), elle se ferme. */
        public bool $sessionIsOpen,
        /** Le nombre de personnes dit (1 à 99, déjà vérifié) ; `null` : celui du compte. */
        public ?int $servings = null,
        /**
         * Seulement quand l'utilisateur répond à des propositions de recettes qu'Alexa a FAITES en nommant autre chose : la phrase d'ORIGINE, à retenir
         * pour la recette que cette nouvelle phrase désigne. `null` : une demande ordinaire.
         */
        public ?string $rememberAs = null,
        /** Les produits ont déjà été cherchés pour cette phrase (l'utilisateur a répondu « une recette ») : « ou un produit ? » n'est plus proposé. */
        public bool $otherSideTried = false,
    ) {
    }
}
