<?php

namespace Repas\User\Application\ChangeUserStatus;


use Repas\User\Domain\Model\UserStatus;

/**
 * Un administrateur fixe le statut d'un compte, quel que soit l'ancien (ADR 0021).
 */
final readonly class ChangeUserStatusCommand
{
    public function __construct(
        public string $userId,
        public UserStatus $status,
    ) {
    }
}
