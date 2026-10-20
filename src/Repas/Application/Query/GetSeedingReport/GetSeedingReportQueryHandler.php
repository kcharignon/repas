<?php

namespace Repas\Repas\Application\Query\GetSeedingReport;

use Repas\Repas\Application\Loader\IngredientSeedingReportLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetSeedingReportQueryHandler
{
    public function __construct(
        private IngredientSeedingReportLoader $reportLoader,
    ) {
    }

    public function __invoke(GetSeedingReportQuery $query): SeedingReportDTO
    {
        return $this->reportLoader->report($query->only, $query->except, $query->complete);
    }
}
