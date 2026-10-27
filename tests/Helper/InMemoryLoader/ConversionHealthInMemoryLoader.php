<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\ConversionHealthLoader;
use Repas\Repas\Application\Query\GetConversionHealth\ConversionHealthDTO;

/**
 * Double idiot : renvoie le rapport programmé tel quel (ni filtre ni calcul) et compte ses appels.
 */
class ConversionHealthInMemoryLoader implements ConversionHealthLoader
{
    public int $reportCalls = 0;

    public function __construct(
        private readonly ConversionHealthDTO $report,
    ) {
    }

    public function report(): ConversionHealthDTO
    {
        ++$this->reportCalls;

        return $this->report;
    }
}
