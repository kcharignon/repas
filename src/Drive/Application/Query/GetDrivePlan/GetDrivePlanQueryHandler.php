<?php

namespace Repas\Drive\Application\Query\GetDrivePlan;

use Repas\Drive\Application\Loader\DrivePlanLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetDrivePlanQueryHandler
{
    public function __construct(
        private DrivePlanLoader $drivePlanLoader,
    ) {
    }

    public function __invoke(GetDrivePlanQuery $query): DrivePlanDTO
    {
        return $this->drivePlanLoader->get($query->userId, $query->shoppingListId);
    }
}
