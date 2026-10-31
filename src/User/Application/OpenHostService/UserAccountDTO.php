<?php

namespace Repas\User\Application\OpenHostService;

/**
 * Ce que le contexte `User` publie d'un compte à qui le lit (ADR 0035) : si le compte existe et est actif (ADR 0021).
 */
final readonly class UserAccountDTO
{
    public function __construct(
        public string $id,
        public bool $active,
    ) {
    }
}
