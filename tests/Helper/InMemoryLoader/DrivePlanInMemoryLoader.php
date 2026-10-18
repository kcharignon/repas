<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Drive\Application\Loader\DrivePlanLoader;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanDTO;

/**
 * Double idiot : renvoie le plan programmé quels que soient l'utilisateur et la liste demandés et enregistre ses appels.
 */
class DrivePlanInMemoryLoader implements DrivePlanLoader
{
    /** @var list<array{string, string}> utilisateur et liste reçus, dans l'ordre des appels */
    public array $getCalls = [];

    public function __construct(
        private readonly DrivePlanDTO $plan,
    ) {
    }

    public function get(string $userId, string $shoppingListId): DrivePlanDTO
    {
        $this->getCalls[] = [$userId, $shoppingListId];

        return $this->plan;
    }
}
