<?php

namespace Repas\Repas\Application\Command\CreateUnit;


use Repas\Repas\Domain\Exception\UnitException;
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

    public function __invoke(CreateUnitCommand $command): string
    {
        $unit = Unit::create(
            name: $command->name,
            symbol: $command->symbol,
            dimension: $command->dimension,
            baseFactor: $command->baseFactor,
        );

        // Le slug dérive du nom : enregistrer sous un slug existant écraserait l'unité
        if ($this->unitRepository->exists($unit->getSlug())) {
            throw UnitException::alreadyExists($unit->getSlug());
        }

        $this->unitRepository->save($unit);

        return $unit->getSlug();
    }
}
