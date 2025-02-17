<?php

namespace Repas\Repas\Application\CreateUnit;


use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Unit;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CreateUnitHandler
{


    public function __construct(
        private UnitRepository $unitRepository
    ) {
    }

    public function __invoke(CreateUnitCommand $command): void
    {
        $unit = Unit::create(
            name: $command->name,
            symbol: $command->symbol
        );

        $this->unitRepository->save($unit);
    }
}
