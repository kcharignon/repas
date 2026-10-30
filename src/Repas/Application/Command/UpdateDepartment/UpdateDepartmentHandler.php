<?php

namespace Repas\Repas\Application\Command\UpdateDepartment;

use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Model\DepartmentIcon;
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

        // L'icône demandée est contrôlée avant toute écriture (`422` sinon) ; absente du corps, elle n'est pas touchée (ING-016 B)
        $icon = $command->changeIcon ? DepartmentIcon::parse($command->icon) : null;

        $department->update(name: $command->name, image: $command->image);
        if ($command->changeIcon) {
            $department->changeIcon($icon);
        }

        $this->departmentRepository->save($department);
    }
}
