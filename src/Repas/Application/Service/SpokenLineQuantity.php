<?php

namespace Repas\Repas\Application\Service;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;

/**
 * La quantité dite à voix haute, ramenée à l'unité de la ligne de la liste (ADR 0029 décision 5, amendée le 2026-10-25 ; la même règle sert à
 * ajouter et à retirer, décision 12) :
 *
 * - rien de dit, ou l'unité de la ligne : la quantité telle quelle ;
 * - une unité que l'ingrédient ne connaît pas (ni son unité d'achat ni une de ses unités compatibles) : laissée de côté, la quantité telle quelle
 *   dans l'unité de la ligne ;
 * - une unité connue, mais la ligne existe dans une autre : la quantité est CONVERTIE dans celle de la ligne quand une conversion existe, sinon
 *   prise telle quelle ;
 * - une unité connue et aucune ligne : c'est l'unité de la ligne à créer.
 *
 * L'ingrédient n'est lu que lorsqu'une unité est dite et diffère de celle de la ligne.
 */
final readonly class SpokenLineQuantity
{
    public function __construct(
        private IngredientRepository $ingredientRepository,
        private UnitRepository $unitRepository,
        private ConversionService $conversionService,
    ) {
    }

    /**
     * L'unité de la ligne, la quantité dans cette unité, et si l'unité dite n'est pas celle de la ligne.
     *
     * @param ?string $lineUnitSlug     l'unité de la ligne existante, `null` s'il n'y en a pas encore
     * @param string  $purchaseUnitSlug l'unité d'achat de l'ingrédient : celle d'une ligne à créer quand rien n'est dit
     *
     * @return array{string, float, bool}
     *
     * @throws IngredientException l'ingrédient n'existe pas (seulement quand une unité est dite)
     */
    public function inLineUnit(string $ingredientSlug, int $quantity, ?string $saidUnit, ?string $lineUnitSlug, string $purchaseUnitSlug): array
    {
        $amount = (float) $quantity;
        // La ligne n'a qu'une unité : celle qu'elle a déjà, à défaut l'unité d'achat de l'ingrédient
        $lineUnit = $lineUnitSlug ?? $purchaseUnitSlug;

        // Rien de dit, ou l'unité de la ligne : la quantité s'ajoute telle quelle
        if ($saidUnit === null || $saidUnit === $lineUnit) {
            return [$lineUnit, $amount, false];
        }

        $ingredient = $this->ingredientRepository->findOneBySlug($ingredientSlug);
        $knownUnit = $this->knownUnit($ingredient, $saidUnit);
        if ($knownUnit === null) {
            // L'ingrédient ne connaît pas cette unité : dans l'unité de la ligne, et Alexa le dit
            return [$lineUnit, $amount, true];
        }
        if ($lineUnitSlug === null) {
            // Première ligne de cet ingrédient : l'unité dite est celle de la ligne
            return [$saidUnit, $amount, false];
        }

        // La ligne existe dans une autre unité : on convertit dans la sienne, sinon on prend la quantité dite telle quelle
        try {
            $converted = $this->conversionService->convertTo($ingredient, $amount, $knownUnit, $this->unitRepository->findOneBySlug($lineUnit));
            // Trois décimales : une conversion de kilos en grammes ne laisse pas de poussière de virgule flottante
            $amount = max(round($converted, 3), 0.001);
        } catch (IngredientException) {
            // Aucun chemin de conversion : la quantité dite est prise telle quelle, dans l'unité de la ligne
        }

        return [$lineUnit, $amount, true];
    }

    /**
     * L'unité dite si l'ingrédient la connaît (son unité d'achat par défaut ou une de ses unités compatibles), sinon `null`.
     */
    private function knownUnit(Ingredient $ingredient, string $unitSlug): ?Unit
    {
        if ($ingredient->getDefaultPurchaseUnit()->getSlug() === $unitSlug) {
            return $ingredient->getDefaultPurchaseUnit();
        }

        return $ingredient->getCompatibleUnits()->find(static fn(Unit $unit): bool => $unit->getSlug() === $unitSlug);
    }
}
