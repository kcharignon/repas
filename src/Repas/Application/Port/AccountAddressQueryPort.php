<?php

namespace Repas\Repas\Application\Port;

/**
 * L'adresse du propriétaire d'une conversion, à l'écran de l'administrateur (`GET /api/v1/conversions`). Adapté depuis le contexte `User`
 * (ADR 0035) ; aucun autre besoin d'adresse n'existe dans `Repas`.
 */
interface AccountAddressQueryPort
{
    /**
     * @param list<string> $userIds
     *
     * @return array<string, string> l'adresse par identifiant ; un compte inconnu n'y figure pas
     */
    public function addressesOf(array $userIds): array;
}
