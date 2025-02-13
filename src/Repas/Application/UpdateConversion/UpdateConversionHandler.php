<?php

namespace Repas\Repas\Application\UpdateConversion;

use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateConversionHandler
{

    public function __construct(
        private ConversionRepository $conversionRepository,
        private UnitRepository $unitRepository,
        private IngredientRepository $ingredientRepository,
    ) {
    }

    public function __invoke(UpdateConversionCommand $command): void
    {
        $conversion = $this->conversionRepository->findById($command->id);
        $startUnit = $this->unitRepository->findOneBySlug($command->startUnitSlug);
        $endUnit = $this->unitRepository->findOneBySlug($command->endUnitSlug);
        $ingredient = $command->ingredientSlug ? $this->ingredientRepository->findOneBySlug($command->ingredientSlug) : null;

        $conversion->update(
            startUnit: $startUnit,
            endUnit: $endUnit,
            coefficient: $command->coefficient,
            ingredient: $ingredient,
        );

        $this->conversionRepository->save($conversion);
    }
}
