<?php

namespace Repas\Repas\Infrastructure\Acl;

use Repas\Repas\Application\Port\CatalogOwnerCommandPort;
use Repas\User\Application\OpenHostService\CatalogOwnerCommandOHS;

/**
 * Couche anticorruption (ADR 0035) : le propriétaire du catalogue publié par `User`, tel que les écritures d'ingrédients l'attendent.
 */
final readonly class CatalogOwnerCommandAdapter implements CatalogOwnerCommandPort
{
    public function __construct(
        private CatalogOwnerCommandOHS $owner,
    ) {
    }

    public function ownerId(): ?string
    {
        return $this->owner->ownerId();
    }
}
