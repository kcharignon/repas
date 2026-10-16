<?php

namespace Repas\User\Application\SendEmailVerification;


/**
 * Renvoie le lien de confirmation d'adresse email d'un compte en attente (ADR 0020).
 */
final readonly class SendEmailVerificationCommand
{
    public function __construct(
        public string $email,
    ) {
    }
}
