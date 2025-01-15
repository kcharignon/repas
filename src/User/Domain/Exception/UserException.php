<?php

namespace Repas\User\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

class UserException extends DomainException
{
    public static function NotFound(): static
    {
        return new static('USER_NOT_FOUND', 404);
    }

    public static function Banned(): static
    {
        return new static('USER_BANNED', 403);
    }
}
