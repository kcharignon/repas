<?php

namespace Repas\User\Application\OpenHostService;

use Repas\User\Application\Loader\UserLoader;

/**
 * Open Host Service de lecture du contexte `User` (ADR 0035) : l'adresse de comptes donnés. Une adresse n'est publiée qu'à l'écran de
 * l'administrateur qui la montre (les conversions, avec le propriétaire de chacune) : un autre besoin d'adresse se discute, il ne passe pas
 * par la liste des comptes (`UserDirectoryQueryOHS`).
 */
final readonly class AccountAddressQueryOHS
{
    public function __construct(
        private UserLoader $userLoader,
    ) {
    }

    /**
     * @param list<string> $userIds
     *
     * @return array<string, string> l'adresse par identifiant ; un compte inconnu n'y figure pas
     */
    public function addressesOf(array $userIds): array
    {
        return $userIds === [] ? [] : $this->userLoader->emailsOf($userIds);
    }
}
