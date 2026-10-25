<?php

namespace Repas\Voice\Application\Command\AddSpokenItem;


/**
 * Ce que `AddSpokenItemHandler` rend au webhook (exception à « une commande ne renvoie rien », ADR 0015 §12) : la phrase à dire et si la
 * séance reste ouverte. Le catalogue des textes est celui de `Voice\Domain\Model\VoiceReplies`.
 */
final readonly class AddSpokenItemResult
{
    public function __construct(
        public string $speech,
        public bool $keepSessionOpen,
        /** L'article a été ajouté à la liste (faux : l'ingrédient n'a pas été trouvé, rien n'a été écrit). */
        public bool $added,
    ) {
    }
}
