<?php

namespace Repas\User\Application\OpenHostService;

use Repas\User\Application\Loader\UserLoader;
use Repas\User\Domain\Service\CatalogOwnerEmail;

/**
 * Open Host Service de lecture du contexte `User` (ADR 0035) : le propriétaire du catalogue d'ingrédients (ADR 0028, amendement du
 * 2026-10-20), pour un autre contexte qui lit (qui voit le marqueur « de base », rapport de l'amorçage). Le même compte que celui du
 * `CatalogOwnerCommandOHS` : le compte protégé (`CatalogOwnerEmail`), jamais « tout administrateur ».
 *
 * `$overrideEmail` vient de `INGREDIENT_CATALOG_OWNER_EMAIL` (`config/services.yaml`), vide dans `.env` et posée nulle part en production.
 */
final readonly class CatalogOwnerQueryOHS
{
    public function __construct(
        private UserLoader $userLoader,
        private ?string $overrideEmail = null,
    ) {
    }

    /**
     * L'identifiant du compte propriétaire du catalogue, ou `null` s'il n'existe pas.
     */
    public function ownerId(): ?string
    {
        return $this->userLoader->idOfEmail(CatalogOwnerEmail::of($this->overrideEmail));
    }
}
