<?php

namespace Repas\Repas\Infrastructure\Acl;

use Repas\Repas\Application\Port\AccountAddressQueryPort;
use Repas\User\Application\OpenHostService\AccountAddressQueryOHS;

/**
 * Couche anticorruption (ADR 0035) : les adresses publiées par `User`, telles que l'écran des conversions les attend.
 */
final readonly class AccountAddressQueryAdapter implements AccountAddressQueryPort
{
    public function __construct(
        private AccountAddressQueryOHS $addresses,
    ) {
    }

    public function addressesOf(array $userIds): array
    {
        return $this->addresses->addressesOf($userIds);
    }
}
