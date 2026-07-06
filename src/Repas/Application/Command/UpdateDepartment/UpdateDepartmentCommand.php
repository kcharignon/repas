<?php

namespace Repas\Repas\Application\Command\UpdateDepartment;


readonly class UpdateDepartmentCommand
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $image,
    ) {
    }
}
