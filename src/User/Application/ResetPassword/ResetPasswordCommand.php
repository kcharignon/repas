<?php

namespace Repas\User\Application\ResetPassword;


/**
 * Fixe un nouveau mot de passe grâce au lien reçu par email. La commande ne porte que le hachage du secret
 * (`PasswordResetToken::hash()`), comme `VerifyEmailCommand` (ADR 0015 §1, ADR 0026).
 */
final readonly class ResetPasswordCommand
{
    public function __construct(
        public string $tokenHash,
        public string $newPassword,
    ) {
    }
}
