<?php

namespace Repas\Repas\Domain\Model;

use Repas\Repas\Domain\Exception\ShoppingListException;

/**
 * Ce que le passage au drive a fait d'une ligne (ADR 0041) : l'ingrédient, l'issue et, quand elle est connue, la raison, un code fixe de l'agent
 * (`BLOCKED`, `NETWORK`…), jamais un morceau de page ni un texte libre : le rapport vient d'un site qu'on ne contrôle pas.
 */
final readonly class DriveLineAttempt
{
    /** La forme d'une raison : un code en majuscules, 40 caractères au plus. */
    public const string REASON_PATTERN = '/^[A-Z][A-Z0-9_]{0,39}$/D';

    /**
     * @throws ShoppingListException la raison n'est pas un code
     */
    public function __construct(
        public string $ingredientSlug,
        public DriveLineOutcome $outcome,
        public ?string $reason = null,
    ) {
        if ($reason !== null && preg_match(self::REASON_PATTERN, $reason) !== 1) {
            throw ShoppingListException::invalidDriveReason();
        }
    }
}
