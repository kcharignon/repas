<?php

namespace Repas\User\Application\VerifyEmail;


/**
 * Confirme l'adresse email d'un compte par le lien reçu. La commande ne porte que le hachage du secret
 * (`EmailVerificationToken::hash()`), comme `IssueRefreshTokenCommand` (ADR 0015 §1).
 */
final readonly class VerifyEmailCommand
{
    public function __construct(
        public string $tokenHash,
    ) {
    }
}
