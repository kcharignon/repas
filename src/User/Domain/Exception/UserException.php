<?php

namespace Repas\User\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

class UserException extends DomainException
{
    public static function NotFound(): static
    {
        return new static(404, "USER_NOT_FOUND");
    }

    public static function Banned(): static
    {
        return new static(403, "USER_BANNED");
    }
}
