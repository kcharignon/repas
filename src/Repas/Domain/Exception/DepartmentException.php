<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

class DepartmentException extends DomainException
{
    public static function notFound(): static
    {
        return new static("DEPARTMENT_NOT_FOUND", 404);
    }
}
