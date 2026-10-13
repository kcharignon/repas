<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\UnitLoader;
use Repas\Repas\Application\Query\ListUnits\UnitDTO;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class UnitInMemoryLoader implements UnitLoader
{
    public int $listCalls = 0;

    /**
     * @param list<UnitDTO> $units
     */
    public function __construct(
        private readonly array $units = [],
    ) {
    }

    public function list(): array
    {
        $this->listCalls++;

        return $this->units;
    }
}
