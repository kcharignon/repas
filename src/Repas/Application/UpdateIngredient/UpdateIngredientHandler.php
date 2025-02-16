<?php

namespace Repas\Repas\Application\UpdateIngredient;

use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateIngredientHandler
{
    public function __construct(
        private DepartmentRepository $departmentRepository,
        private UnitRepository $unitRepository,
        private IngredientRepository $ingredientRepository,
        private ConversionRepository $conversionRepository,
    ) {
    }

    /**
     * @throws DepartmentException
     * @throws UnitException
     * @throws IngredientException
     */
    public function __invoke(UpdateIngredientCommand $command): void
    {
        $ingredient = $this->ingredientRepository->findOneBySlug($command->slug);

        if (!$ingredient->getDefaultCookingUnit()->isEqual($ingredient->getDefaultPurchaseUnit())) {
            $conversion = $this->conversionRepository->findByIngredientAndStartUnitAndEndUnit(
                $ingredient,
                $ingredient->getDefaultPurchaseUnit(),
                $ingredient->getDefaultCookingUnit(),
            );

            $conversion->update(
                startUnit: $ingredient->getDefaultPurchaseUnit(),
                endUnit: $ingredient->getDefaultCookingUnit(),
                coefficient: $command->coefficient,
                ingredient: $ingredient,
            );
            $this->conversionRepository->save($conversion);
        }

        $ingredient->update(
            name: $command->name,
            image: $command->image,
            department: $this->departmentRepository->findOneBySlug($command->departmentSlug),
            defaultCookingUnit: $this->unitRepository->findOneBySlug($command->defaultCookingUnitSlug),
            defaultPurchaseUnit: $this->unitRepository->findOneBySlug($command->defaultPurchaseUnitSlug),
        );

        $this->ingredientRepository->save($ingredient);
    }
}
