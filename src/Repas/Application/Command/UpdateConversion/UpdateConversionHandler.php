<?php

namespace Repas\Repas\Application\Command\UpdateConversion;

use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;
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

    /**
     * Une conversion propre à un ingrédient d'un autre est inconnue, et on ne la rattache pas à l'ingrédient d'un autre : un administrateur
     * n'a de passe-droit que sur les conversions générales et sur celles de SES ingrédients (ADR 0028, amendement, D4).
     *
     * @throws ConversionException
     * @throws IngredientException
     */
    public function __invoke(UpdateConversionCommand $command): void
    {
        $conversion = $this->conversionRepository->findById($command->id);
        if ($conversion->getIngredient() !== null && !$conversion->getIngredient()->isOwnedBy($command->userId)) {
            throw ConversionException::notFound($command->id);
        }
        $startUnit = $this->unitRepository->findOneBySlug($command->startUnitSlug);
        $endUnit = $this->unitRepository->findOneBySlug($command->endUnitSlug);
        $ingredient = $command->ingredientSlug ? $this->ingredientRepository->findOneBySlug($command->ingredientSlug) : null;
        $ingredient?->assertOwnedBy($command->userId);

        $conversion->update(
            startUnit: $startUnit,
            endUnit: $endUnit,
            coefficient: $command->coefficient,
            ingredient: $ingredient,
        );

        $this->conversionRepository->save($conversion);
    }
}
