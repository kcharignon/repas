<?php

namespace Repas\Repas\Application\CreateDepartment;

use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Model\Department;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CreateDepartmentHandler
{
    public function __construct(
        private DepartmentRepository $departmentInMemoryRepository,
    ) {
    }

    public function __invoke(CreateDepartmentCommand $command): void
    {
        $department = Department::create($command->name, $command->image);
        $this->departmentInMemoryRepository->save($department);
    }

}
