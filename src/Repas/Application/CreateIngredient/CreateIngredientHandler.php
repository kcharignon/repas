<?php

namespace Repas\Repas\Application\CreateIngredient;


use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;


#[AsMessageHandler]
readonly class CreateIngredientHandler
{
    public function __construct(
        private DepartmentRepository $departmentRepository,
        private UnitRepository $unitRepository,
        private IngredientRepository $ingredientRepository,
    ) {
    }

    /**
     * @throws DepartmentException
     * @throws UnitException
     */
    public function __invoke(CreateIngredientCommand $command): void
    {
        $ingredient = Ingredient::create(
            name: $command->name,
            image: $command->image,
            department: $this->departmentRepository->findOneBySlug($command->departmentSlug),
            defaultCookingUnit: $this->unitRepository->findOneBySlug($command->defaultCookingUnitSlug),
            defaultPurchaseUnit: $this->unitRepository->findOneBySlug($command->defaultPurchaseUnitSlug),
        );

        $this->ingredientRepository->save($ingredient);
    }

}
