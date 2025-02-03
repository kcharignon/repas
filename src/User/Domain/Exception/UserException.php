<?php

namespace Repas\User\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

class UserException extends DomainException
{
    public static function NotFound(string $user): static
    {
        return new static(sprintf("User '%s' not found", $user), 404);
    }

    public static function Banned(): static
    {
        return new static('USER_BANNED', 403);
    }
}
