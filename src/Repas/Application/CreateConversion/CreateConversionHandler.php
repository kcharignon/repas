<?php

namespace Repas\Repas\Application\CreateConversion;

use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CreateConversionHandler
{

    public function __construct(
        private ConversionRepository $conversionRepository,
        private UnitRepository $unitRepository,
        private IngredientRepository $ingredientRepository,
        private UuidGenerator $uuidGenerator,
    ) {
    }

    public function __invoke(CreateConversionCommand $command): void
    {
        $startUnit = $this->unitRepository->findOneBySlug($command->startUnitSlug);
        $endUnit = $this->unitRepository->findOneBySlug($command->endUnitSlug);
        $ingredient = ($command->ingredientSlug) ? $this->ingredientRepository->findOneBySlug($command->ingredientSlug) : null;

        $conversion = Conversion::create(
            id: $this->uuidGenerator::new(),
            startUnit: $startUnit,
            endUnit: $endUnit,
            coefficient: $command->coefficient,
            ingredient: $ingredient,
        );

        $this->conversionRepository->save($conversion);
    }
}
