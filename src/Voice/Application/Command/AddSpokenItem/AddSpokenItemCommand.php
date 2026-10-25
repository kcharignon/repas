<?php

namespace Repas\Voice\Application\Command\AddSpokenItem;


/**
 * L'utilisateur vient de dire un article (« ajoute du pain ») : trouver l'ingrédient qu'il désigne parmi les siens et l'ajouter à sa
 * liste de courses (ADR 0029 décisions 3 à 5, VOX-204). `userId` vient du jeton d'accès résolu, jamais du corps de la requête ; la
 * phrase est celle du créneau `item`, déjà nettoyée et bornée (`AlexaRequest`).
 */
final readonly class AddSpokenItemCommand
{
    public function __construct(
        public string $userId,
        public string $phrase,
        /** La séance était déjà ouverte : la réponse invite à continuer et la séance reste ouverte. Sinon (appel direct), elle se ferme. */
        public bool $sessionIsOpen,
    ) {
    }
}
