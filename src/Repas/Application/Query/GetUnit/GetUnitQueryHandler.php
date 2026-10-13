<?php

namespace Repas\Repas\Application\Query\GetUnit;

use Repas\Repas\Application\Loader\UnitLoader;
use Repas\Repas\Application\Query\ListUnits\UnitDTO;
use Repas\Repas\Domain\Exception\UnitException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetUnitQueryHandler
{
    public function __construct(
        private UnitLoader $loader,
    ) {
    }

    /**
     * @throws UnitException
     */
    public function __invoke(GetUnitQuery $query): UnitDTO
    {
        return $this->loader->get($query->slug);
    }
}
