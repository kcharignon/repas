<?php

namespace Repas\User\Application\ActivateUser;


/**
 * Un administrateur confirme lui-même un compte en attente (ADR 0021).
 */
final readonly class ActivateUserCommand
{
    public function __construct(
        public string $userId,
    ) {
    }
}
