<?php

namespace Repas\User\Application\Query\IsEmailAlreadyUsed;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class IsEmailAlreadyUsedQuery implements QueryInterface
{
    public function __construct(
        public string $email,
    ) {
    }
}
