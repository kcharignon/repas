<?php

namespace Repas\User\Application\Query\GetCurrentUser;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetCurrentUserQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
    ) {
    }
}
