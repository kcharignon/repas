<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\ListUnits\UnitDTO;
use Repas\Repas\Domain\Exception\UnitException;

interface UnitLoader
{
    /**
     * Toutes les unités, triées par slug.
     *
     * @return list<UnitDTO>
     */
    public function list(): array;

    /**
     * @throws UnitException
     */
    public function get(string $slug): UnitDTO;
}
