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

        // La règle : l'unité des recettes se convertit TOUJOURS en l'unité d'achat. Tout ce qui peut la refuser l'est avant la première écriture ;
        // le contrôle final, sur le graphe tel qu'il sera, la garantit ensuite (une erreur annule la commande entière : le bus est transactionnel).
        $unitsDiffer = !$newCookingUnit->isEqual($newPurchaseUnit);
        if ($unitsDiffer && $this->writeConversion($ingredient, $command->coefficient, $newCookingUnit, $newPurchaseUnit)) {
            // Le graphe relu APRÈS l'écriture : le service garde le sien en cache, périmé dès qu'une conversion change
            $this->conversionService->clear();
        }
        if ($unitsDiffer) {
            $this->conversionService->assertConvertible($ingredient, $newCookingUnit, $newPurchaseUnit);
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
     * Les deux unités diffèrent : le coefficient (« 1 unité d'achat = N unités de recette ») est facultatif quand le graphe de l'ingrédient les
     * relie déjà (une conversion universelle, une conversion propre, un chemin de plusieurs étapes), obligatoire sinon. Donné, il met à jour la
     * conversion PROPRE de l'ingrédient (celle de l'ancienne paire d'unités) ou en crée une ; jamais une conversion universelle, qui sert à tous
     * les ingrédients et ne devient pas la sienne. Un coefficient qui répète ce que le graphe dit déjà n'écrit rien.
     *
     * @return bool une conversion a été écrite (le graphe en cache est alors périmé)
     * @throws IngredientException
     */
    private function writeConversion(Ingredient $ingredient, ?float $coefficient, Unit $newCookingUnit, Unit $newPurchaseUnit): bool
    {
        $connected = $this->conversionService->canConvert($ingredient, $newCookingUnit, $newPurchaseUnit);
        if ($coefficient === null) {
            if (!$connected) {
                throw IngredientException::coefficientRequired();
            }

            return false;
        }
        if (!is_finite($coefficient) || $coefficient <= 0) {
            throw IngredientException::invalidCoefficient();
        }

        // Sa propre conversion de l'ancienne paire (achat -> recettes), si elle existe
        $own = $ingredient->hasSameUnitInCookingAndPurchase()
            ? null
            : $this->conversionRepository->findByIngredientAndStartUnitAndEndUnit($ingredient, $ingredient->getDefaultPurchaseUnit(), $ingredient->getDefaultCookingUnit());

        if ($own instanceof Conversion) { // Met à jour la conversion existante
            $own->update(
                startUnit: $newPurchaseUnit,
                endUnit: $newCookingUnit,
                coefficient: $coefficient,
                ingredient: $ingredient,
            );
            $this->conversionRepository->save($own);

            return true;
        }
        if ($connected && abs($this->conversionService->convertTo($ingredient, 1, $newPurchaseUnit, $newCookingUnit) - $coefficient) <= 1e-9 * $coefficient) {
            return false; // le graphe dit déjà la même chose : une ligne de plus ne ferait que doubler
        }

        $this->conversionRepository->save(Conversion::create(UuidGenerator::new(), $newPurchaseUnit, $newCookingUnit, $coefficient, $ingredient));

        return true;
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
