<?php

namespace Repas\Repas\Application\Command\UpdateUnit;

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

        // La mesure d'abord : si elle est incohérente, l'unité n'a encore été touchée en rien
        if ($command->replaceMeasure) {
            $unit->setMeasure($command->dimension, $command->baseFactor);
        }

        $unit->update(
            $command->name,
            $command->symbol,
        );

        $this->unitRepository->save($unit);
    }
}
