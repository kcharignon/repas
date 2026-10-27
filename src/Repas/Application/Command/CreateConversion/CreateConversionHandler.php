<?php

namespace Repas\Repas\Application\Command\CreateConversion;

use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Conversion;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CreateConversionHandler
{

    public function __construct(
        private ConversionRepository $conversionRepository,
        private UnitRepository $unitRepository,
        private IngredientRepository $ingredientRepository,
    ) {
    }

    /**
     * Sans ingrédient, la conversion est générale (commune à tous les ingrédients) ; avec un ingrédient, il doit être à l'utilisateur :
     * celui d'un autre est inconnu, un administrateur compris (ADR 0028, amendement, D4).
     *
     * @throws IngredientException
     * @throws ConversionException l'ingrédient a déjà toutes ses conversions propres (`Conversion::MAX_PER_INGREDIENT`)
     */
    public function __invoke(CreateConversionCommand $command): void
    {
        $startUnit = $this->unitRepository->findOneBySlug($command->startUnitSlug);
        $endUnit = $this->unitRepository->findOneBySlug($command->endUnitSlug);
        $ingredient = ($command->ingredientSlug) ? $this->ingredientRepository->findOneBySlug($command->ingredientSlug) : null;
        $ingredient?->assertOwnedBy($command->userId);
        if ($ingredient !== null && $this->conversionRepository->ingredientHasAtLeast($ingredient, Conversion::MAX_PER_INGREDIENT)) {
            throw ConversionException::limitReached();
        }

        $conversion = Conversion::create(
            id: $command->id,
            startUnit: $startUnit,
            endUnit: $endUnit,
            coefficient: $command->coefficient,
            ingredient: $ingredient,
        );

        $this->conversionRepository->save($conversion);
    }
}
