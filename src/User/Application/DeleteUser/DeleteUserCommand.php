<?php

namespace Repas\User\Application\DeleteUser;


/**
 * Un administrateur supprime un compte comme s'il n'avait jamais existé (ADR 0021).
 */
final readonly class DeleteUserCommand
{
    public function __construct(
        public string $userId,
    ) {
    }
}
