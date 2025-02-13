<?php

namespace Repas\Repas\Application\CreateDepartment;


readonly class CreateDepartmentCommand
{

    public function __construct(
        public string $name,
        public string $image,
    ) {
    }
}
