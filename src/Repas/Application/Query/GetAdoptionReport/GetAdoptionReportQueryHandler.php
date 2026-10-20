<?php

namespace Repas\Repas\Application\Query\GetAdoptionReport;

use Repas\Repas\Application\Loader\IngredientAdoptionReportLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetAdoptionReportQueryHandler
{
    public function __construct(
        private IngredientAdoptionReportLoader $reportLoader,
    ) {
    }

    public function __invoke(GetAdoptionReportQuery $query): AdoptionReportDTO
    {
        return $this->reportLoader->report($query->only, $query->except);
    }
}
