<?php

namespace Repas\Repas\Application\UpdateUnit;

use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateUnitHandler
{

    public function __construct(
        private UnitRepository $unitRepository,
    ) {
    }

    /**
     * @throws UnitException
     */
    public function __invoke(UpdateUnitCommand $command): void
    {
        $unit = $this->unitRepository->findOneBySlug($command->slug);

        $unit->update(
            $command->name,
            $command->symbol,
        );

        $this->unitRepository->save($unit);
    }
}
