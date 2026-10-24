<?php

namespace Repas\Repas\Application\Command\UpdateIngredient;

use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Application\Interface\IngredientCatalogOwner;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateIngredientHandler
{
    public function __construct(
        private DepartmentRepository $departmentRepository,
        private UnitRepository $unitRepository,
        private IngredientRepository $ingredientRepository,
        private ConversionRepository $conversionRepository,
        private ConversionService $conversionService,
        private IngredientCatalogOwner $catalogOwner,
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

        // Le marqueur « de base » (ADR 0028, amendement) : absent, on n'y touche pas ; identique à l'actuel, c'est sans effet pour tout
        // propriétaire de l'ingrédient ; différent, seul le propriétaire du catalogue le change (`403` sinon), avant toute écriture. Le
        // propriétaire du catalogue n'est lu que dans ce dernier cas : un `PUT` ordinaire ne coûte aucune requête de plus.
        if ($command->isBase !== null && $command->isBase !== $ingredient->isBase()) {
            $ingredient->assertBaseMarkerSettableBy($command->userId, $this->catalogOwner->ownerId());
            $ingredient->changeBase($command->isBase);
        }

        $newCookingUnit = $this->unitRepository->findOneBySlug($command->defaultCookingUnitSlug);
        $newPurchaseUnit = $this->unitRepository->findOneBySlug($command->defaultPurchaseUnitSlug);

        // On recupere l'ancienne conversion
        $conversion = null;
        if (!$ingredient->hasSameUnitInCookingAndPurchase()) {
            $conversion = $this->conversionRepository->findByIngredientAndStartUnitAndEndUnit(
                $ingredient,
                $ingredient->getDefaultPurchaseUnit(),
                $ingredient->getDefaultCookingUnit(),
            );
        }

        if (!$newCookingUnit->isEqual($newPurchaseUnit)) {
            if ($command->coefficient === null) {
                throw IngredientException::coefficientRequired();
            }

            if ($conversion instanceof Conversion) { // Met à jour la conversion existante
                $conversion->update(
                    startUnit: $newPurchaseUnit,
                    endUnit: $newCookingUnit,
                    coefficient: $command->coefficient,
                    ingredient: $ingredient,
                );
            } else { // Créer une nouvelle conversion
                $conversion = Conversion::create(
                    UuidGenerator::new(),
                    $newPurchaseUnit,
                    $newCookingUnit,
                    $command->coefficient,
                    $ingredient,
                );
            }
            $this->conversionRepository->save($conversion);
        }

        $ingredient->update(
            name: $command->name,
            image: $command->image ?? $ingredient->getImage(),
            department: $this->departmentRepository->findOneBySlug($command->departmentSlug),
            defaultCookingUnit: $newCookingUnit,
            defaultPurchaseUnit: $newPurchaseUnit,
            compatibleUnits: $this->getCompatibleUnit($ingredient, $newCookingUnit),
        );
        $this->ingredientRepository->save($ingredient);
    }

    /**
     * @return Tab<Unit>
     */
    private function getCompatibleUnit(Ingredient $ingredient, Unit $cookingUnit): Tab
    {
        // Récupération des unités compatibles à partir des unités de cuisine (et d'achat)
        // Comme une conversion existe entre unite de cuisine et d'achat alors inutile de faire la recherche depuis les deux
        return $this->conversionService->getConvertibleUnits($ingredient, $cookingUnit);
    }
}
