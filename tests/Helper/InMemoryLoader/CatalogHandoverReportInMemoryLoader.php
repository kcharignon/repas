<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\CatalogHandoverReportLoader;
use Repas\Repas\Application\Query\GetCatalogHandoverReport\CatalogHandoverReportDTO;

/**
 * Double idiot : renvoie le rapport programmé tel quel (ni filtre ni calcul) et compte ses appels.
 */
class CatalogHandoverReportInMemoryLoader implements CatalogHandoverReportLoader
{
    public int $reportCalls = 0;

    public function __construct(
        private readonly CatalogHandoverReportDTO $report,
    ) {
    }

    public function report(): CatalogHandoverReportDTO
    {
        ++$this->reportCalls;

        return $this->report;
    }
}
