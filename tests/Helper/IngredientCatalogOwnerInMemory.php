<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Application\Interface\IngredientCatalogOwner;

/**
 * Le propriétaire du catalogue à la main : `new IngredientCatalogOwnerInMemory('owner-id')`, ou `new IngredientCatalogOwnerInMemory(null)` pour
 * le cas où le compte n'existe pas. Il compte ses appels, pour prouver qu'un cas d'usage ne le lit que s'il en a besoin.
 */
final class IngredientCatalogOwnerInMemory implements IngredientCatalogOwner
{
    public int $calls = 0;

    public function __construct(private ?string $ownerId = null)
    {
    }

    public function ownerId(): ?string
    {
        ++$this->calls;

        return $this->ownerId;
    }
}
