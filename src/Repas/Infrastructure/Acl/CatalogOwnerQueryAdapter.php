<?php

namespace Repas\Repas\Infrastructure\Acl;

use Repas\Repas\Application\Port\CatalogOwnerQueryPort;
use Repas\User\Application\OpenHostService\CatalogOwnerQueryOHS;

/**
 * Couche anticorruption (ADR 0035) : le propriétaire du catalogue publié par `User`, tel que les lectures d'ingrédients l'attendent.
 */
final readonly class CatalogOwnerQueryAdapter implements CatalogOwnerQueryPort
{
    public function __construct(
        private CatalogOwnerQueryOHS $owner,
    ) {
    }

    public function ownerId(): ?string
    {
        return $this->owner->ownerId();
    }
}
