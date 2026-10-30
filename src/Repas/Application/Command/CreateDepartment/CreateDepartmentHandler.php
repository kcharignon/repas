<?php

namespace Repas\Repas\Application\Command\CreateDepartment;

use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\DepartmentIcon;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CreateDepartmentHandler
{
    public function __construct(
        private DepartmentRepository $departmentRepository,
    ) {
    }

    public function __invoke(CreateDepartmentCommand $command): string
    {
        $department = Department::create($command->name, $command->image, DepartmentIcon::parse($command->icon));
        // Le slug dérive du nom : enregistrer sous un slug existant écraserait le rayon
        if ($this->departmentRepository->exists($department->getSlug())) {
            throw DepartmentException::alreadyExists($department->getSlug());
        }

        $this->departmentRepository->save($department);

        return $department->getSlug();
    }

}
