<?php

namespace Repas\User\Application\OpenHostService;

use Repas\User\Application\Loader\UserLoader;

/**
 * Open Host Service de lecture du contexte `User` (ADR 0035) : la liste des comptes (identifiant et statut) pour les rapports
 * d'exploitation d'un autre contexte, qui compte ensuite sur ses propres tables. Aucune adresse ici : voir `AccountAddressQueryOHS`.
 */
final readonly class UserDirectoryQueryOHS
{
    public function __construct(
        private UserLoader $userLoader,
    ) {
    }

    /**
     * Tous les comptes, triés par identifiant.
     *
     * @return list<UserDirectoryEntryDTO>
     */
    public function accounts(): array
    {
        return array_map(
            static fn(array $account): UserDirectoryEntryDTO => new UserDirectoryEntryDTO($account['id'], $account['status']),
            $this->userLoader->listStatuses(),
        );
    }
}
