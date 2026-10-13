<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\UnitLoader;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Application\Query\ListUnits\UnitDTO;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class UnitInMemoryLoader implements UnitLoader
{
    public int $listCalls = 0;

    /** @var list<string> */
    public array $getCalls = [];

    /**
     * @param list<UnitDTO> $units
     */
    public function __construct(
        private readonly array $units = [],
        private readonly ?UnitDTO $unit = null,
    ) {
    }

    public function list(): array
    {
        $this->listCalls++;

        return $this->units;
    }

    public function get(string $slug): UnitDTO
    {
        $this->getCalls[] = $slug;

        return $this->unit ?? throw UnitException::notFound($slug);
    }
}
