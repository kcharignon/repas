<?php

namespace Repas\Voice\Application\Command\AddSpokenItem;


use Repas\Voice\Domain\Model\SpokenDialogueState;

/**
 * Ce que `AddSpokenItemHandler` et `AnswerSpokenDialogueHandler` rendent au webhook (exception à « une commande ne renvoie rien », ADR 0015
 * §12) : la phrase à dire, si la séance reste ouverte, et le dialogue qui continue. Le catalogue des textes est celui de
 * `Voice\Domain\Model\VoiceReplies`.
 */
final readonly class AddSpokenItemResult
{
    public function __construct(
        public string $speech,
        public bool $keepSessionOpen,
        /** L'article a été ajouté à la liste (faux : une question est posée ou rien n'a été compris, rien n'a été écrit dans la liste). */
        public bool $added,
        /** Le dialogue d'un produit inconnu en cours : écrit dans `sessionAttributes`, rendu par Alexa à la requête suivante. `null` : aucun. */
        public ?SpokenDialogueState $nextState = null,
        /** Ce qu'Alexa répète après un silence : la question en cours. `null` : « Que veux-tu ajouter ? ». */
        public ?string $reprompt = null,
    ) {
    }
}
