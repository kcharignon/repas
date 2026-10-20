<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\IngredientSeedingReportLoader;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingReportDTO;

/**
 * Double idiot : renvoie le rapport programmé tel quel (ni filtre ni calcul) et enregistre ses appels.
 */
class IngredientSeedingReportInMemoryLoader implements IngredientSeedingReportLoader
{
    /** @var list<array{list<string>, list<string>, bool}> la sélection reçue à chaque appel */
    public array $reportCalls = [];

    public function __construct(
        private readonly SeedingReportDTO $report,
    ) {
    }

    public function report(array $only, array $except, bool $complete): SeedingReportDTO
    {
        $this->reportCalls[] = [$only, $except, $complete];

        return $this->report;
    }
}
