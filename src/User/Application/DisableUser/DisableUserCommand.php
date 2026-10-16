<?php

namespace Repas\User\Application\DisableUser;


/**
 * Un administrateur désactive un compte, en attente ou actif (ADR 0021). `actingUserId` vient du jeton de
 * l'administrateur, jamais du corps de la requête.
 */
final readonly class DisableUserCommand
{
    public function __construct(
        public string $userId,
        public string $actingUserId,
    ) {
    }
}
