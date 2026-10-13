<?php

namespace Repas\Repas\Application\Query\ListUnits;

use Repas\Repas\Application\Loader\UnitLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListUnitsQueryHandler
{
    public function __construct(
        private UnitLoader $unitLoader,
    ) {
    }

    /**
     * @return list<UnitDTO>
     */
    public function __invoke(ListUnitsQuery $query): array
    {
        return $this->unitLoader->list();
    }
}
