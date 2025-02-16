<?php

namespace Repas\Repas\Application\CreateIngredient;


use Repas\Repas\Domain\Event\CreateIngredientWithConversionEvent;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;


#[AsMessageHandler]
readonly class CreateIngredientHandler
{
    public function __construct(
        private DepartmentRepository $departmentRepository,
        private UnitRepository $unitRepository,
        private IngredientRepository $ingredientRepository,
        private UserRepository $userRepository,
        private EventDispatcherInterface $eventDispatcher,
        private ConversionService $conversionService,
    ) {
    }

    /**
     * @throws DepartmentException
     * @throws UnitException
     * @throws UserException
     */
    public function __invoke(CreateIngredientCommand $command): void
    {
        $cookingUnit = $this->unitRepository->findOneBySlug($command->defaultCookingUnitSlug);
        $purchaseUnit = $this->unitRepository->findOneBySlug($command->defaultPurchaseUnitSlug);
        $ingredient = Ingredient::create(
            name: $command->name,
            image: $command->image,
            department: $this->departmentRepository->findOneBySlug($command->departmentSlug),
            defaultCookingUnit: $cookingUnit,
            defaultPurchaseUnit: $purchaseUnit,
            creator: $command->ownerId !== null ? $this->userRepository->findOneById($command->ownerId) : null,
        );

        $this->ingredientRepository->save($ingredient);


        // Si l'ingrédient n'a pas la même unité dans les recettes qu'à l'achat et qu'on ne peut pas convertir entre les deux,
        // alors on crée une conversion entre les deux unités.
        if (!$cookingUnit->isEqual($purchaseUnit) && !$this->conversionService->canConvertToPurchaseUnit($ingredient)) {
            $this->eventDispatcher->dispatch(new CreateIngredientWithConversionEvent(
                $ingredient->getSlug(),
                $command->coefficient,
            ));
        }
    }
}
