<?php

namespace Repas\Repas\Application\Command\CreateDepartment;


readonly class CreateDepartmentCommand
{

    public function __construct(
        public string $name,
        public string $image,
    ) {
    }
}
