<?php

namespace Repas\Repas\Application\Service;


use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\QuantityBounds;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;

/**
 * Ce que partagent l'ajout, la modification et le retrait d'une conversion PROPRE à un ingrédient de l'utilisateur (le formulaire de
 * conversions de chaque ingrédient) : les contrôles d'une conversion, la règle « l'unité des recettes se convertit toujours en l'unité
 * d'achat » jugée sur le jeu de conversions tel qu'il SERAIT, et la remise à jour des unités compatibles.
 *
 * Seul l'administrateur crée une conversion universelle (`/api/v1/conversions`) : rien ici n'écrit une conversion sans ingrédient.
 */
readonly class IngredientOwnConversions
{
    public function __construct(
        private ConversionRepository $conversionRepository,
        private IngredientRepository $ingredientRepository,
        private UnitRepository $unitRepository,
        private ConversionService $conversionService,
    ) {
    }

    /**
     * Les deux unités d'une conversion : un coefficient fini de 0,000001 à 1 000 000 (le HTTP le valide déjà ; le cas d'usage ne se fie pas à son appelant),
     * deux unités connues et différentes.
     *
     * @return array{0: Unit, 1: Unit} l'unité de départ puis celle d'arrivée
     * @throws IngredientException le coefficient n'est pas un nombre fini de 0,000001 à 1 000 000
     * @throws UnitException une unité est inconnue
     * @throws ConversionException les deux unités sont les mêmes
     */
    public function units(string $startUnitSlug, string $endUnitSlug, float $coefficient): array
    {
        if (!QuantityBounds::isCoefficient($coefficient)) {
            throw IngredientException::invalidCoefficient();
        }
        $start = $this->unitRepository->findOneBySlug($startUnitSlug);
        $end = $this->unitRepository->findOneBySlug($endUnitSlug);
        if ($start->isEqual($end)) {
            throw ConversionException::sameUnit();
        }

        return [$start, $end];
    }

    /**
     * Une conversion propre de cet ingrédient, par son identifiant. Une conversion d'un autre ingrédient ou universelle est inconnue : cette
     * route n'en modifie ni n'en retire aucune.
     *
     * @throws ConversionException
     */
    public function ownConversion(Ingredient $ingredient, string $conversionId): Conversion
    {
        $conversion = $this->conversionRepository->findById($conversionId);
        if (!$conversion instanceof Conversion || !$ingredient->isEqual($conversion->getIngredient())) {
            throw ConversionException::notFound($conversionId);
        }

        return $conversion;
    }

    /**
     * L'ingrédient n'a pas déjà sa propre conversion entre ces deux unités, dans un sens ou dans l'autre : on modifie celle qui existe.
     * `$exceptId` est la conversion qu'on est en train de modifier.
     *
     * @throws ConversionException
     */
    public function assertPairIsFree(Ingredient $ingredient, Unit $start, Unit $end, ?string $exceptId = null): void
    {
        foreach ($this->conversionRepository->findByIngredient($ingredient) as $own) {
            if ($own->getId() === $exceptId) {
                continue;
            }
            $same = $own->getStartUnit()->isEqual($start) && $own->getEndUnit()->isEqual($end);
            $reversed = $own->getStartUnit()->isEqual($end) && $own->getEndUnit()->isEqual($start);
            if ($same || $reversed) {
                throw ConversionException::alreadyExists();
            }
        }
    }

    /**
     * Les conversions de l'ingrédient (les siennes puis les universelles) telles qu'elles seraient après le changement : `$replacement`
     * prend la place de la conversion de même identifiant, ou, sans remplaçante, la conversion est retirée.
     *
     * @return list<Conversion>
     */
    public function conversionsAfter(Ingredient $ingredient, string $conversionId, ?Conversion $replacement): array
    {
        $after = [];
        foreach ($this->conversionRepository->findByIngredientOrCommon($ingredient) as $conversion) {
            if ($conversion->getId() !== $conversionId) {
                $after[] = $conversion;
            } elseif ($replacement instanceof Conversion) {
                $after[] = $replacement;
            }
        }

        return $after;
    }

    /**
     * La règle : l'unité des recettes de l'ingrédient se convertit TOUJOURS en son unité d'achat. Jugée sur le jeu de conversions
     * hypothétique, AVANT d'écrire : une conversion dont l'ingrédient a besoin ne se retire pas et ne se change pas en une qui ne relie plus
     * rien. Les lignes de recettes dans d'autres unités ne bloquent pas : la lecture de la recette les signale.
     *
     * @param iterable<Conversion> $conversionsAfter
     * @throws ConversionException
     */
    public function assertRecipesStillConvert(Ingredient $ingredient, iterable $conversionsAfter): void
    {
        $cooking = $ingredient->getDefaultCookingUnit();
        $purchase = $ingredient->getDefaultPurchaseUnit();
        if ($cooking->isEqual($purchase)) {
            return;
        }
        if (!$this->conversionService->canConvertWith($conversionsAfter, $cooking, $purchase)) {
            throw ConversionException::requiredByIngredient();
        }
    }

    /**
     * Après l'écriture : le graphe en cache est périmé, les unités compatibles de l'ingrédient (celles qu'on peut choisir dans une recette)
     * se recalculent sur le graphe final.
     */
    public function refreshCompatibleUnits(Ingredient $ingredient): void
    {
        $this->conversionService->clear();
        $ingredient->setCompatibleUnits($this->conversionService->getConvertibleUnits($ingredient, $ingredient->getDefaultCookingUnit()));
        $this->ingredientRepository->save($ingredient);
    }
}
