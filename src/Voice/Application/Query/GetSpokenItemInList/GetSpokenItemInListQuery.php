<?php

namespace Repas\Voice\Application\Query\GetSpokenItemInList;


use Repas\Shared\Application\Interface\QueryInterface;

/**
 * « Est-ce qu'il y a des croquettes dans la liste ? » (ADR 0029 décision 12, VOX-304) : lire ce que la liste active de l'utilisateur contient pour
 * la phrase du créneau `item`. Une lecture : rien n'est écrit, aucune liste n'est créée. `userId` vient du jeton d'accès résolu, jamais du corps
 * de la requête.
 */
final readonly class GetSpokenItemInListQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
        public string $phrase,
        /** La séance était déjà ouverte : elle le reste, sans rien redemander. Sinon (appel direct), elle se ferme. */
        public bool $sessionIsOpen,
    ) {
    }
}
