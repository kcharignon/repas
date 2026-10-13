<?php

namespace Repas\User\Application\Query\GetCurrentUser;

use Repas\User\Application\Loader\UserLoader;
use Repas\User\Domain\Exception\UserException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetCurrentUserQueryHandler
{
    public function __construct(
        private UserLoader $userLoader,
    ) {
    }

    /**
     * @throws UserException
     */
    public function __invoke(GetCurrentUserQuery $query): CurrentUserDTO
    {
        return $this->userLoader->get($query->userId);
    }
}
