<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Drive\Application\Loader\DrivePlanLoader;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanDTO;
use Repas\Drive\Domain\Model\Retailer;

/**
 * Double idiot : renvoie le plan programmé quels que soient l'utilisateur et la liste demandés et enregistre ses appels.
 */
class DrivePlanInMemoryLoader implements DrivePlanLoader
{
    /** @var list<array{string, string, Retailer}> utilisateur, liste et enseigne reçus, dans l'ordre des appels */
    public array $getCalls = [];

    public function __construct(
        private readonly DrivePlanDTO $plan,
    ) {
    }

    public function get(string $userId, string $shoppingListId, Retailer $retailer): DrivePlanDTO
    {
        $this->getCalls[] = [$userId, $shoppingListId, $retailer];

        return $this->plan;
    }
}
