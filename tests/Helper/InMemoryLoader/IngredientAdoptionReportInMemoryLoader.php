<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\IngredientAdoptionReportLoader;
use Repas\Repas\Application\Query\GetAdoptionReport\AdoptionReportDTO;

/**
 * Double idiot : renvoie le rapport programmé tel quel (ni filtre ni calcul) et enregistre ses appels.
 */
class IngredientAdoptionReportInMemoryLoader implements IngredientAdoptionReportLoader
{
    /** @var list<array{list<string>, list<string>}> les sélections reçues à chaque appel */
    public array $reportCalls = [];

    public function __construct(
        private readonly AdoptionReportDTO $report,
    ) {
    }

    public function report(array $only, array $except): AdoptionReportDTO
    {
        $this->reportCalls[] = [$only, $except];

        return $this->report;
    }
}
