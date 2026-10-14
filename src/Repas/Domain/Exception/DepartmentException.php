<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class DepartmentException extends DomainException
{
    public static function notFound(): static
    {
        return new static("DEPARTMENT_NOT_FOUND", 404);
    }

    public static function alreadyExists(string $slug): self
    {
        return new self(sprintf("Department %s already exists.", $slug), 409);
    }
}
