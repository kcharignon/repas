<?php

namespace Repas\User\Application\RegisterNewUser;


/**
 * Inscription avec la seule adresse (ADR 0020 amendé). Aucun mot de passe : il se choisit après le clic sur le lien reçu par email.
 * `userId` n'est celui du compte que si l'adresse est nouvelle.
 */
readonly class RegisterNewUserCommand
{
    public function __construct(
        public string $userId,
        public string $email,
        public int $defaultServing,
    ) {
    }
}
