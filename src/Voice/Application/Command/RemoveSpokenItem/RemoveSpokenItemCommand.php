<?php

namespace Repas\Voice\Application\Command\RemoveSpokenItem;


/**
 * L'utilisateur vient de dire qu'il retire un article (« enlève du pain », « retire deux paquets de croquettes ») : trouver la ligne de sa liste
 * active qui le porte et la diminuer (ADR 0029 décision 12, VOX-304). `userId` vient du jeton d'accès résolu, jamais du corps de la requête ; la
 * phrase est celle du créneau `item`, déjà nettoyée et bornée (`AlexaRequest`). La quantité (1 à 99) et l'unité dites sont facultatives, comme
 * pour l'ajout : 1 sans rien dire.
 */
final readonly class RemoveSpokenItemCommand
{
    public function __construct(
        public string $userId,
        public string $phrase,
        /** La séance était déjà ouverte : elle le reste, sans rien redemander. Sinon (appel direct), elle se ferme. */
        public bool $sessionIsOpen,
        /** Combien, de 1 à 99 (`AlexaRequest::quantity`, vérifiée avant d'arriver ici). */
        public int $quantity = 1,
        /** L'unité dite (slug du catalogue), convertie dans celle de la ligne quand l'ingrédient la connaît. `null` : rien de dit. */
        public ?string $unitSlug = null,
    ) {
    }
}
