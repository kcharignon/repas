<?php

namespace Repas\Repas\Application\Query\GetCatalogHandoverReport;

use Repas\Repas\Application\Loader\CatalogHandoverReportLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetCatalogHandoverReportQueryHandler
{
    public function __construct(
        private CatalogHandoverReportLoader $reportLoader,
    ) {
    }

    public function __invoke(GetCatalogHandoverReportQuery $query): CatalogHandoverReportDTO
    {
        return $this->reportLoader->report();
    }
}
