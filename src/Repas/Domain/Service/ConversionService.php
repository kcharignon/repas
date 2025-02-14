<?php

namespace Repas\Repas\Domain\Service;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\Tab;

readonly class ConversionService
{
    private Tab $graphs;

    public function __construct(
        private ConversionRepository $conversionRepository,
        private UnitRepository $unitRepository,
    ) {
        $this->graphs = Tab::newEmptyTyped('array');
    }

    public function canConvertToPurchaseUnit(Ingredient $ingredient): bool
    {
        try {
            $this->convertToPurchaseUnit($ingredient, 1, $ingredient->getDefaultCookingUnit());
        } catch (IngredientException $e) {
            return false;
        }
        return true;
    }

    /**
     * Récupère toutes les unités vers lesquelles un ingrédient peut être converti à partir d'une unité donnée.
     *
     * @param Ingredient $ingredient L'ingrédient concerné.
     * @param Unit $startingUnit L'unité de départ.
     *
     * @return Tab<Unit> Liste des unités accessibles.
     */
    public function getConvertibleUnits(Ingredient $ingredient, Unit $startingUnit): Tab
    {
        // Récupérer le graph des conversions pour cet ingrédient
        $graph = $this->generateGraph($ingredient);

        // Initialiser la liste des unités trouvées (avec l'unité de départ)
        $units = Tab::fromArray($startingUnit);

        // Vérifier si l'unité de départ existe dans le graphe
        if (!isset($graph[$startingUnit->getSlug()])) {
            return $units;
        }

        $queue = [$startingUnit->getSlug()];
        $visited = [$startingUnit->getSlug() => true];

        // BFS pour explorer toutes les unités atteignables
        while (!empty($queue)) {
            $current = array_shift($queue);

            if (!isset($graph[$current])) {
                continue;
            }

            foreach ($graph[$current] as $edge) {
                $next = $edge['to'];

                if (!isset($visited[$next])) {
                    $visited[$next] = true;
                    $queue[] = $next;

                    // Récupérer l'entité de l'unité correspondante
                    $unit = $this->unitRepository->findOneBySlug($next);
                    $units[] = $unit;
                }
            }
        }

        return $units;
    }

    /**
     * Convertit une quantité dans une unité donnée en quantité exprimée dans l'unité d'achat par défaut de l'ingrédient.
     * La conversion peut nécessiter plusieurs étapes, y compris des conversions inverses.
     * Utilise le parcours en largeur (BFS) pour trouver le chemin avec le moins d'étapes.
     *
     * @throws IngredientException si aucun chemin de conversion n'est trouvé
     */
    public function convertToPurchaseUnit(Ingredient $ingredient, float $quantity, Unit $unit): float
    {
        $purchaseUnit = $ingredient->getDefaultPurchaseUnit();

        // Si l'unité fournie est déjà l'unité d'achat, aucun calcul n'est nécessaire.
        if ($unit->getId() === $purchaseUnit->getId()) {
            return $quantity;
        }

        // Récupérer le graph des conversions pour un ingredient
        $graph = $this->generateGraph($ingredient);

        // Utiliser BFS pour trouver le chemin de conversion avec le moins d'étapes
        $totalCoefficient = $this->findConversionCoefficientBFS($unit->getId(), $purchaseUnit->getId(), $graph);
        if ($totalCoefficient === null) {
            throw IngredientException::cannotConvertToUnit($ingredient, $unit, $purchaseUnit);
        }

        return $quantity * $totalCoefficient;
    }

    /**
     * Recherche en largeur (BFS) d'un chemin dans le graphe de conversion.
     *
     * @param string $start  L'ID de l'unité de départ.
     * @param string $target L'ID de l'unité cible (unité d'achat).
     * @param array  $graph  Graphe des conversions au format :
     *                       [ unitId => [ ['to' => unitId, 'weight' => coefficient], ... ] ]
     *
     * @return float|null Le produit des coefficients le long du chemin trouvé, ou null si aucun chemin n'est trouvé.
     */
    private function findConversionCoefficientBFS(string $start, string $target, array $graph): ?float
    {
        // La file d'attente contient des paires : [currentUnitId, cumulativeCoefficient]
        $queue = [[$start, 1.0]];
        // Tableau pour éviter de revisiter un nœud.
        $visited = [];
        $visited[$start] = true;

        while (!empty($queue)) {
            list($current, $coeff) = array_shift($queue);

            if ($current === $target) {
                return $coeff;
            }

            if (!isset($graph[$current])) {
                continue;
            }

            foreach ($graph[$current] as $edge) {
                $next = $edge['to'];
                if (!isset($visited[$next])) {
                    $visited[$next] = true;
                    $newCoeff = $coeff * $edge['weight'];
                    $queue[] = [$next, $newCoeff];
                }
            }
        }

        return null;
    }

    private function generateGraph(Ingredient $ingredient): array
    {
        if (!isset($this->graphs[$ingredient->getSlug()])) {
            // Recuperation de toutes les conversions d'un ingredient
            $conversions = $this->conversionRepository->findByIngredient($ingredient);

            // Construire un graphe bidirectionnel des conversions.
            // Pour chaque conversion, on ajoute deux arêtes :
            // - De startUnit à endUnit avec un poids égal au coefficient.
            // - De endUnit à startUnit avec un poids égal à 1/coefficient.
            $graph = [];
            foreach ($conversions->toArray() as $conversion) {
                $startSlug = $conversion->getStartUnit()->getSlug();
                $endSlug   = $conversion->getEndUnit()->getSlug();
                $coef    = $conversion->getCoefficient();

                $graph[$startSlug][] = ['to' => $endSlug, 'weight' => $coef];
                $graph[$endSlug][]   = ['to' => $startSlug, 'weight' => 1 / $coef];
            }

            $this->graphs->add($graph, $ingredient->getSlug());
        }

        return $this->graphs[$ingredient->getSlug()];
    }

    /**
     * @throws IngredientException
     */
    public function convertRecipeRowToPurchaseUnit(RecipeRow $row): float
    {
        return $this->convertToPurchaseUnit(
            $row->getIngredient(),
            $row->getQuantity(),
            $row->getUnit(),
        );
    }
}
