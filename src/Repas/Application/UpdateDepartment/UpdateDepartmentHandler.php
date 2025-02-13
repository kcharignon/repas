<?php

namespace Repas\Repas\Application\UpdateDepartment;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateDepartmentHandler
{

    public function __construct()
    {
    }

    public function __invoke(UpdateDepartmentCommand $command): void
    {
        // TODO: Implement __invoke() method.
    }
}
