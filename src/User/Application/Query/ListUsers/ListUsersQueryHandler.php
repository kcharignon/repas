<?php

namespace Repas\User\Application\Query\ListUsers;

use Repas\User\Application\Loader\UserLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListUsersQueryHandler
{
    public function __construct(
        private UserLoader $userLoader,
    ) {
    }

    /**
     * @return list<UserSummaryDTO>
     */
    public function __invoke(ListUsersQuery $query): array
    {
        return $this->userLoader->list();
    }
}
