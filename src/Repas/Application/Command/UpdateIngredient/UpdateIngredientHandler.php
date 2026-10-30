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
use Repas\Repas\Domain\Model\QuantityBounds;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Application\Interface\IngredientCatalogOwner;
use Repas\Shared\Domain\Tool\StringTool;
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
        $newDepartment = $this->departmentRepository->findOneBySlug($command->departmentSlug);

        // L'unicité (ADR 0028, amendement du 2026-10-30, ING-016) : un propriétaire n'a pas deux ingrédients de même nom normalisé DANS LE
        // MÊME RAYON. Elle n'est jugée que si le nom normalisé OU le rayon change (modifier l'image ou les unités d'un ingrédient qui a déjà
        // un doublon, hérité d'une copie, ne doit pas être refusé), et sans compter l'ingrédient lui-même. Avant toute écriture.
        $newSluggedName = StringTool::slugify($command->name);
        if (($newSluggedName !== $ingredient->getSluggedName() || $newDepartment->getSlug() !== $ingredient->getDepartment()->getSlug())
            && $this->ingredientRepository->creatorHasNameInDepartment($ingredient->getCreatorId(), $newSluggedName, $newDepartment->getSlug(), $ingredient->getSlug())) {
            throw IngredientException::nameAlreadyUsedInDepartment();
        }

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

        // La première lettre du nom est mise en majuscule en silence, le reste est conservé (ADR 0030)
        $ingredient->update(
            name: StringTool::capitalizeFirst($command->name),
            image: $command->image ?? $ingredient->getImage(),
            department: $newDepartment,
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
        if (!QuantityBounds::isCoefficient($coefficient)) {
            throw IngredientException::invalidCoefficient();
        }

        // Sa propre conversion de l'ancienne paire d'unités, dans les DEUX sens : une conversion enregistrée « à l'envers » (1 recette = n achat,
        // par le formulaire des conversions propres ou l'ancien code) est la même conversion, jamais l'occasion d'en écrire une seconde.
        $own = $this->ownConversionOfTheOldPair($ingredient);

        if ($own instanceof Conversion) {
            if ($this->isTheSamePair($own, $newPurchaseUnit, $newCookingUnit)) {
                // Même paire : on change le nombre en GARDANT le sens de la ligne (« 1 achat = N recettes », ou « 1 recette = 1/N achat »)
                $reversed = $own->getStartUnit()->isEqual($newCookingUnit);
                $value = $reversed ? 1 / $coefficient : $coefficient;
                if ($reversed && abs($own->getCoefficient() - $value) <= 1e-9 * $value) {
                    return false; // la ligne dit déjà cela (à l'arrondi du quotient près) : on ne la réécrit pas
                }
                $own->update(
                    startUnit: $own->getStartUnit(),
                    endUnit: $own->getEndUnit(),
                    coefficient: $value,
                    ingredient: $ingredient,
                );
            } else {
                // Nouvelle paire : la ligne de l'ancienne paire est réécrite pour elle, dans le sens naturel achat -> recettes (c'est ce
                // que dit le formulaire : « 1 unité d'achat = N unités de recette »)
                $own->update(
                    startUnit: $newPurchaseUnit,
                    endUnit: $newCookingUnit,
                    coefficient: $coefficient,
                    ingredient: $ingredient,
                );
            }
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
     * La conversion propre de l'ingrédient qui relie ses unités AVANT la modification, dans un sens ou dans l'autre. Quand deux lignes la
     * disent (héritage d'un ancien défaut), celle du sens achat -> recettes passe d'abord, sinon la plus ancienne : toujours la même, et
     * jamais une troisième n'est écrite.
     */
    private function ownConversionOfTheOldPair(Ingredient $ingredient): ?Conversion
    {
        if ($ingredient->hasSameUnitInCookingAndPurchase()) {
            return null;
        }

        $candidates = array_values($this->conversionRepository->findOwnBetween($ingredient, $ingredient->getDefaultPurchaseUnit(), $ingredient->getDefaultCookingUnit())->toArray());
        foreach ($candidates as $candidate) {
            if ($candidate->getStartUnit()->isEqual($ingredient->getDefaultPurchaseUnit())) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * La ligne relie-t-elle exactement ces deux unités (dans un sens ou dans l'autre) ?
     */
    private function isTheSamePair(Conversion $conversion, Unit $purchaseUnit, Unit $cookingUnit): bool
    {
        return ($conversion->getStartUnit()->isEqual($purchaseUnit) && $conversion->getEndUnit()->isEqual($cookingUnit))
            || ($conversion->getStartUnit()->isEqual($cookingUnit) && $conversion->getEndUnit()->isEqual($purchaseUnit));
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
