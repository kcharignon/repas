<?php

namespace Repas\User\Application\Query\IsEmailAlreadyUsed;

use Repas\User\Application\Loader\UserLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class IsEmailAlreadyUsedQueryHandler
{
    public function __construct(
        private UserLoader $userLoader,
    ) {
    }

    public function __invoke(IsEmailAlreadyUsedQuery $query): bool
    {
        return $this->userLoader->emailExists($query->email);
    }
}
