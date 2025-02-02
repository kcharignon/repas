<?php

namespace Repas\Repas\Domain\Service;

use Repas\Repas\Domain\Exception\ConversionException; // à personnaliser si besoin
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;

class ConversionService
{
    public function __construct(
        private ConversionRepository $conversionRepository
    ) {
    }

    /**
     * Convertit une quantité dans une unité donnée en quantité exprimée dans l'unité d'achat par défaut de l'ingrédient.
     * La conversion peut nécessiter plusieurs étapes, y compris des conversions inverses.
     * Utilise le parcours en largeur (BFS) pour trouver le chemin avec le moins d'étapes.
     *
     * @throws \RuntimeException si aucun chemin de conversion n'est trouvé
     */
    public function convertToPurchaseUnit(Ingredient $ingredient, float $quantity, Unit $unit): float
    {
        $purchaseUnit = $ingredient->getDefaultPurchaseUnit();

        // Si l'unité fournie est déjà l'unité d'achat, aucun calcul n'est nécessaire.
        if ($unit->getId() === $purchaseUnit->getId()) {
            return $quantity;
        }

        // Récupérer toutes les conversions associées à l'ingrédient.
        $conversions = $this->conversionRepository->findByIngredient($ingredient);

        // Construire un graphe bidirectionnel des conversions.
        // Pour chaque conversion, on ajoute deux arêtes :
        // - De startUnit à endUnit avec un poids égal au coefficient.
        // - De endUnit à startUnit avec un poids égal à 1/coefficient.
        $graph = [];
        foreach ($conversions->toArray() as $conversion) {
            $startId = $conversion->getStartUnit()->getId();
            $endId   = $conversion->getEndUnit()->getId();
            $coef    = $conversion->getCoefficient();

            $graph[$startId][] = ['to' => $endId, 'weight' => $coef];
            $graph[$endId][]   = ['to' => $startId, 'weight' => 1 / $coef];
        }

        // Utiliser BFS pour trouver le chemin de conversion avec le moins d'étapes
        $totalCoefficient = $this->findConversionCoefficientBFS($unit->getId(), $purchaseUnit->getId(), $graph);
        if ($totalCoefficient === null) {
            throw new \RuntimeException(sprintf(
                "No conversion path found from unit '%s' to purchase unit '%s'.",
                $unit->getId(),
                $purchaseUnit->getId()
            ));
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
}
