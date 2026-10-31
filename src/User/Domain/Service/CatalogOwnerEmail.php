<?php

namespace Repas\User\Domain\Service;

use Repas\User\Domain\Model\User;

/**
 * L'adresse (normalisée) du compte propriétaire du catalogue d'ingrédients (ADR 0028, amendement du 2026-10-20) : le compte protégé, ou, en
 * développement seulement, celui que désigne `INGREDIENT_CATALOG_OWNER_EMAIL`. Un seul compte, jamais « tout administrateur ». La règle n'est
 * écrite qu'ici : les deux Open Host Services du propriétaire (écriture et lecture) la partagent.
 */
final class CatalogOwnerEmail
{
    /**
     * @param string|null $overrideEmail la variable `INGREDIENT_CATALOG_OWNER_EMAIL` : vide ou faite d'espaces, pas de surcharge
     */
    public static function of(?string $overrideEmail): string
    {
        $override = $overrideEmail === null ? '' : User::normalizeEmail($overrideEmail);

        return $override !== '' ? $override : User::normalizeEmail(User::PROTECTED_EMAIL);
    }
}
