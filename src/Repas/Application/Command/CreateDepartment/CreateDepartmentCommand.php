<?php

namespace Repas\Repas\Application\Command\CreateDepartment;


readonly class CreateDepartmentCommand
{
    /**
     * @param string|null $icon une valeur de `DepartmentIcon`, `null` : pas d'icône
     */
    public function __construct(
        public string $name,
        public string $image,
        public ?string $icon = null,
    ) {
    }
}
