<?php

namespace Repas\User\Application\OpenHostService;

use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Service\CatalogOwnerEmail;

/**
 * Open Host Service d'écriture du contexte `User` (ADR 0035) : le propriétaire du catalogue d'ingrédients (ADR 0028, amendement du
 * 2026-10-20), pour un autre contexte qui écrit et doit savoir qui peut poser le marqueur « de base » ou qui ne doit pas être amorcé.
 * C'est un seul compte, le compte protégé (`CatalogOwnerEmail`), jamais « tout administrateur ».
 *
 * `$overrideEmail` vient de `INGREDIENT_CATALOG_OWNER_EMAIL` (`config/services.yaml`), vide dans `.env` et posée nulle part en production.
 */
final readonly class CatalogOwnerCommandOHS
{
    public function __construct(
        private UserRepository $users,
        private ?string $overrideEmail = null,
    ) {
    }

    /**
     * L'identifiant du compte propriétaire du catalogue, ou `null` s'il n'existe pas (base vide, compte pas encore créé) : alors personne
     * ne peut poser le marqueur « de base ». Une adresse de surcharge absente ne retombe pas sur le compte protégé.
     */
    public function ownerId(): ?string
    {
        try {
            return $this->users->findOneByEmail(CatalogOwnerEmail::of($this->overrideEmail))->getId();
        } catch (UserException) {
            return null;
        }
    }
}
