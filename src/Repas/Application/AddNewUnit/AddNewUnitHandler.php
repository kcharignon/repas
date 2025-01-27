<?php

namespace Repas\Repas\Application\AddNewUnit;


use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Unit;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class AddNewUnitHandler
{


    public function __construct(
        private UnitRepository $unitRepository
    ) {
    }

    public function __invoke(AddNewUnitCommand $command): void
    {
        $unit = Unit::create(
            name: $command->name,
            symbol: $command->abbreviation
        );

        $this->unitRepository->save($unit);
    }
}
