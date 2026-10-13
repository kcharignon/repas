<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\ListUnits\UnitDTO;

interface UnitLoader
{
    /**
     * Toutes les unités, triées par slug.
     *
     * @return list<UnitDTO>
     */
    public function list(): array;
}
