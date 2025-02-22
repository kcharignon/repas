<?php

namespace Repas\Repas\Application\UpdateDepartment;

use Repas\Repas\Domain\Interface\DepartmentRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateDepartmentHandler
{

    public function __construct(
        private DepartmentRepository $departmentRepository,
    ) {
    }

    public function __invoke(UpdateDepartmentCommand $command): void
    {
        $department = $this->departmentRepository->findOneBySlug($command->slug);

        $department->update(name: $command->name, image: $command->image);

        $this->departmentRepository->save($department);
    }
}
