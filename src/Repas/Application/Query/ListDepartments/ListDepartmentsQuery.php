<?php

namespace Repas\Repas\Application\Query\ListDepartments;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListDepartmentsQuery implements QueryInterface
{
    /**
     * @param string $userId l'utilisateur qui lit : le type de liste d'un rayon est propre à son compte (ADR 0036)
     */
    public function __construct(
        public string $userId,
    ) {
    }
}
