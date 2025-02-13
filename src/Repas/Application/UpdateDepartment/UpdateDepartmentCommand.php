<?php

namespace Repas\Repas\Application\UpdateDepartment;


readonly class UpdateDepartmentCommand
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $image,
    ) {
    }
}
