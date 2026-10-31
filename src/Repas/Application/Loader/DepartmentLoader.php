<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Application\Query\ListDepartments\DepartmentListItemDTO;
use Repas\Repas\Domain\Exception\DepartmentException;

interface DepartmentLoader
{
    /**
     * Tous les rayons, triés par slug croissant, chacun avec le type de liste de courses auquel CET utilisateur l'a rattaché (ADR 0036) :
     * deux lectures au plus, quel que soit le nombre de rayons.
     *
     * @return list<DepartmentListItemDTO>
     */
    public function list(string $userId): array;

    /**
     * @throws DepartmentException
     */
    public function get(string $slug): DepartmentDTO;
}
