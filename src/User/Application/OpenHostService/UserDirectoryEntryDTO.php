<?php

namespace Repas\User\Application\OpenHostService;

/**
 * Ce que le contexte `User` publie de chaque compte à qui dresse un rapport d'exploitation (ADR 0035) : son identifiant et son statut
 * (`PENDING`, `ACTIVE` ou `DISABLED`), jamais son adresse.
 */
final readonly class UserDirectoryEntryDTO
{
    public function __construct(
        public string $id,
        public string $status,
    ) {
    }
}
