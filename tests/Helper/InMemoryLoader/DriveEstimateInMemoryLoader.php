<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Drive\Application\Loader\DriveEstimateLoader;
use Repas\Drive\Application\Query\GetDriveEstimate\DriveEstimateDTO;
use Repas\Drive\Domain\Model\Retailer;

/**
 * Double idiot : renvoie l'estimation programmée quels que soient l'utilisateur et la liste demandés et enregistre ses appels.
 */
class DriveEstimateInMemoryLoader implements DriveEstimateLoader
{
    /** @var list<array{string, string, Retailer}> utilisateur, liste et enseigne reçus, dans l'ordre des appels */
    public array $getCalls = [];

    public function __construct(
        private readonly DriveEstimateDTO $estimate,
    ) {
    }

    public function get(string $userId, string $shoppingListId, Retailer $retailer): DriveEstimateDTO
    {
        $this->getCalls[] = [$userId, $shoppingListId, $retailer];

        return $this->estimate;
    }
}
