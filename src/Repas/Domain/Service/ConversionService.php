<?php

namespace Repas\Repas\Domain\Service;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Conversion;
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

    /**
     * Oublie les graphes de conversions déjà construits. Le service survit aux requêtes en mode worker : appelé entre deux
     * requêtes (tag `kernel.reset`, config/services.yaml), il évite de servir un graphe périmé après la modification d'une conversion.
     */
    public function clear(): void
    {
        foreach ($this->graphs->keys() as $slug) {
            unset($this->graphs[$slug]);
        }
    }

    /**
     * Une ligne de recette doit pouvoir être exprimée dans l'unité d'achat de son ingrédient : sinon la planification de la
     * recette échouerait plus tard. À vérifier à l'enregistrement de la recette.
     *
     * @throws IngredientException si aucun chemin de conversion ne mène de cette unité à l'unité d'achat
     */
    public function assertConvertibleToPurchaseUnit(Ingredient $ingredient, Unit $unit): void
    {
        $this->convertToPurchaseUnit($ingredient, 1, $unit);
    }

    public function canConvertToPurchaseUnit(Ingredient $ingredient): bool
    {
        return $this->canConvert($ingredient, $ingredient->getDefaultCookingUnit(), $ingredient->getDefaultPurchaseUnit());
    }

    /**
     * Le graphe de l'ingrédient, tel qu'il est lu maintenant (le cache de ce service : `clear()` après toute écriture de conversion), relie-t-il
     * ces deux unités ? C'est la règle « l'unité des recettes de l'ingrédient se convertit toujours en son unité d'achat » : l'enregistrement d'un
     * ingrédient, puis l'ajout, la modification et la suppression de ses conversions propres l'appliquent, sur le graphe FINAL.
     */
    public function canConvert(Ingredient $ingredient, Unit $from, Unit $to): bool
    {
        try {
            $this->convertTo($ingredient, 1, $from, $to);
        } catch (IngredientException) {
            return false;
        }

        return true;
    }

    /**
     * @throws IngredientException (`cannotConvertToUnit()`, 422) si aucun chemin de conversion ne mène de `$from` à `$to`
     */
    public function assertConvertible(Ingredient $ingredient, Unit $from, Unit $to): void
    {
        $this->convertTo($ingredient, 1, $from, $to);
    }

    /**
     * La même question pour un jeu de conversions HYPOTHÉTIQUE, avant de l'écrire : celui qu'un ingrédient aurait si on ajoutait, modifiait ou
     * retirait une conversion propre (ses conversions propres et les communes, telles qu'elles seraient). Pur : ni cache, ni lecture.
     *
     * @param iterable<Conversion> $conversions
     */
    public function canConvertWith(iterable $conversions, Unit $from, Unit $to): bool
    {
        $graph = ConversionGraph::fromConversions(array_map(
            static fn(Conversion $conversion): array => [$conversion->getStartUnit()->getSlug(), $conversion->getEndUnit()->getSlug(), $conversion->getCoefficient()],
            is_array($conversions) ? $conversions : iterator_to_array($conversions, false),
        ));

        return $graph->coefficient($from->getSlug(), $to->getSlug()) !== null;
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
        return $this->convertTo($ingredient, $quantity, $unit, $ingredient->getDefaultPurchaseUnit());
    }

    public function convertTo(Ingredient $ingredient, float $quantity, Unit $startUnit, Unit $endUnit): float
    {
        // Si l'unité fournie est déjà l'unité d'achat, aucun calcul n'est nécessaire.
        if ($startUnit->getId() === $endUnit->getId()) {
            return $quantity;
        }

        // Récupérer le graph des conversions pour un ingredient
        $graph = $this->generateGraph($ingredient);

        // Utiliser BFS pour trouver le chemin de conversion avec le moins d'étapes
        $totalCoefficient = $this->findConversionCoefficientBFS($startUnit->getId(), $endUnit->getId(), $graph);
        if ($totalCoefficient === null) {
            throw IngredientException::cannotConvertToUnit($ingredient, $startUnit, $endUnit);
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
        // Le parcours vit dans ConversionGraph : la fusion de deux ingrédients pose la même question sans passer par ce service
        return ConversionGraph::coefficientIn($graph, $start, $target);
    }

    private function generateGraph(Ingredient $ingredient): array
    {
        if (!isset($this->graphs[$ingredient->getSlug()])) {
            // Recuperation de toutes les conversions d'un ingredient
            $conversions = $this->conversionRepository->findByIngredientOrCommon($ingredient);

            // Construire un graphe bidirectionnel des conversions.
            // Pour chaque conversion, on ajoute deux arêtes :
            // - De startUnit à endUnit avec un poids égal au coefficient.
            // - De endUnit à startUnit avec un poids égal à 1/coefficient.
            $graph = ConversionGraph::edgesOf(array_map(
                static fn(Conversion $conversion): array => [$conversion->getStartUnit()->getSlug(), $conversion->getEndUnit()->getSlug(), $conversion->getCoefficient()],
                $conversions->toArray(),
            ));

            $this->graphs->add($graph, $ingredient->getSlug());
        }

        return $this->graphs[$ingredient->getSlug()];
    }

    /**
     * Le graphe de conversion de cet ingrédient (ses conversions propres et les conversions communes), tel que cette classe le parcourt
     * pour convertir : la fusion de deux ingrédients s'en sert pour savoir, sans rien convertir, si une unité mène à l'unité d'achat.
     */
    public function graphOf(Ingredient $ingredient): ConversionGraph
    {
        return new ConversionGraph($this->generateGraph($ingredient));
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
