<?php

namespace Repas\Repas\Application\Query\GetConversionHealth;

use Repas\Repas\Application\Loader\ConversionHealthLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetConversionHealthQueryHandler
{
    public function __construct(
        private ConversionHealthLoader $healthLoader,
    ) {
    }

    public function __invoke(GetConversionHealthQuery $query): ConversionHealthDTO
    {
        return $this->healthLoader->report();
    }
}
