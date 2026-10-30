<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class DepartmentException extends DomainException
{
    // Message du refus d'une icône inconnue (ING-016 B) : le front le distingue, il ne se renomme pas
    public const string UNKNOWN_ICON = 'DEPARTMENT_UNKNOWN_ICON';

    public static function notFound(): static
    {
        return new static("DEPARTMENT_NOT_FOUND", 404);
    }

    /**
     * Une icône qui n'est pas dans `DepartmentIcon` (ING-016 B). Règle métier, `422`, rien n'est écrit.
     */
    public static function unknownIcon(): self
    {
        return new self(self::UNKNOWN_ICON, 422);
    }

    public static function alreadyExists(string $slug): self
    {
        return new self(sprintf("Department %s already exists.", $slug), 409);
    }
}
