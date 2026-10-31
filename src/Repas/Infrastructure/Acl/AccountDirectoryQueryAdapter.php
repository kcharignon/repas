<?php

namespace Repas\Repas\Infrastructure\Acl;

use Repas\Repas\Application\Port\AccountDirectoryQueryPort;
use Repas\User\Application\OpenHostService\UserDirectoryQueryOHS;

/**
 * Couche anticorruption (ADR 0035) : la liste des comptes publiée par `User` devient les statuts, par identifiant, que les rapports de
 * `Repas` attendent.
 */
final readonly class AccountDirectoryQueryAdapter implements AccountDirectoryQueryPort
{
    public function __construct(
        private UserDirectoryQueryOHS $directory,
    ) {
    }

    public function statusesById(): array
    {
        $statuses = [];
        foreach ($this->directory->accounts() as $account) {
            $statuses[$account->id] = $account->status;
        }

        return $statuses;
    }
}
