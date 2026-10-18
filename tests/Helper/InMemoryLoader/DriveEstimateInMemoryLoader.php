<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Drive\Application\Loader\DriveEstimateLoader;
use Repas\Drive\Application\Query\GetDriveEstimate\DriveEstimateDTO;

/**
 * Double idiot : renvoie l'estimation programmée quels que soient l'utilisateur et la liste demandés et enregistre ses appels.
 */
class DriveEstimateInMemoryLoader implements DriveEstimateLoader
{
    /** @var list<array{string, string}> utilisateur et liste reçus, dans l'ordre des appels */
    public array $getCalls = [];

    public function __construct(
        private readonly DriveEstimateDTO $estimate,
    ) {
    }

    public function get(string $userId, string $shoppingListId): DriveEstimateDTO
    {
        $this->getCalls[] = [$userId, $shoppingListId];

        return $this->estimate;
    }
}
