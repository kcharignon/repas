<?php

namespace Repas\User\Application\SendPasswordReset;


/**
 * Envoie par email le lien de réinitialisation du mot de passe d'un compte qui l'a oublié (ADR 0026).
 */
final readonly class SendPasswordResetCommand
{
    public function __construct(
        public string $email,
    ) {
    }
}
