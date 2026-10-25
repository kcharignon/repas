<?php

namespace Repas\Voice\Application\Command\AddSpokenItem;


/**
 * L'utilisateur vient de dire un article (« ajoute du pain ») : trouver l'ingrédient qu'il désigne parmi les siens et l'ajouter à sa
 * liste de courses (ADR 0029 décisions 3 à 5, VOX-204). `userId` vient du jeton d'accès résolu, jamais du corps de la requête ; la
 * phrase est celle du créneau `item`, déjà nettoyée et bornée (`AlexaRequest`). La quantité (1 à 99) et l'unité dites (« deux kilos de
 * tomates ») sont facultatives : une unité d'achat sinon.
 */
final readonly class AddSpokenItemCommand
{
    public function __construct(
        public string $userId,
        public string $phrase,
        /** La séance était déjà ouverte : la réponse invite à continuer et la séance reste ouverte. Sinon (appel direct), elle se ferme. */
        public bool $sessionIsOpen,
        /**
         * Seulement quand l'utilisateur répond à des propositions qu'Alexa a FAITES en nommant autre chose (ADR 0029 décision 11) : la phrase
         * d'ORIGINE (« patates »), à retenir pour l'ingrédient que cette nouvelle phrase désigne (« pomme de terre ») si le choix est sûr ou
         * probable. `null` : un ajout ordinaire (aussi après « Je le crée ? », où il n'y avait rien à corriger).
         */
        public ?string $rememberAs = null,
        /** Combien, de 1 à 99 (`AlexaRequest::quantity`, vérifiée avant d'arriver ici). */
        public int $quantity = 1,
        /** L'unité dite (slug du catalogue), utilisée seulement si l'ingrédient la connaît. `null` : rien de dit. */
        public ?string $unitSlug = null,
    ) {
    }
}
