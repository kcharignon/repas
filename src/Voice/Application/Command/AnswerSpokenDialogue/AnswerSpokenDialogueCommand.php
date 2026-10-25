<?php

namespace Repas\Voice\Application\Command\AnswerSpokenDialogue;


use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\SpokenDialogueState;

/**
 * L'utilisateur répond à une question du dialogue d'un produit inconnu (ADR 0029 décision 11, VOX-303). `userId` vient du jeton d'accès
 * résolu, jamais du corps de la requête. `state` est celui que la séance a rendu : une entrée non fiable, déjà bornée et tolérante
 * (`SpokenDialogueState::fromArray`), dont les slugs ne sont jamais crus. `request` porte l'intention et les créneaux entendus.
 */
final readonly class AnswerSpokenDialogueCommand
{
    public function __construct(
        public string $userId,
        public AlexaRequest $request,
        public SpokenDialogueState $state,
    ) {
    }
}
