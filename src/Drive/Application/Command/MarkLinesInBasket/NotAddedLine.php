<?php

namespace Repas\Drive\Application\Command\MarkLinesInBasket;

use Repas\Drive\Domain\Model\NotAddedOutcome;

/**
 * Une ligne que le passage n'a PAS mise au panier de l'enseigne (ADR 0041) : son ingrédient, ce qui lui est arrivé et, connue, la raison (le code de
 * l'erreur de l'agent). Une trace pour comprendre la liste ; rien ne s'appuie dessus.
 */
readonly class NotAddedLine
{
    /** La forme d'une raison : un code en majuscules, 40 caractères au plus (le même que celui que `Repas` garde). */
    public const string REASON_PATTERN = '/^[A-Z][A-Z0-9_]{0,39}$/D';

    public function __construct(
        public string $ingredientSlug,
        public NotAddedOutcome $outcome,
        public ?string $reason = null,
    ) {
    }
}
