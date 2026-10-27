<?php

namespace Repas\Repas\Infrastructure\Conversion;


use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Repas\Repas\Domain\Service\ConversionGraph;

/**
 * Le graphe de conversion de chaque ingrédient (ses conversions propres PUIS les universelles, l'ordre de `ConversionService`), construit
 * SANS hydrater de modèle : la lecture d'une recette (`convertible` de chaque ligne) et le rapport de santé des conversions
 * (`repas:conversions:check`) posent la même question « cette unité se convertit-elle en l'unité d'achat ? » et donnent la même réponse que
 * la planification, par le même `ConversionGraph` pur.
 */
final readonly class IngredientConversionGraphs
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Les graphes des ingrédients désignés, lus en UNE requête (le nombre de requêtes ne dépend pas du nombre d'ingrédients).
     *
     * @param list<string> $ingredientSlugs
     * @return array<string, ConversionGraph>
     */
    public function forIngredients(array $ingredientSlugs): array
    {
        if ($ingredientSlugs === []) {
            return [];
        }

        $rows = $this->connection->executeQuery(
            'SELECT ingredient, start_unit, end_unit, coefficient FROM conversion WHERE ingredient IS NULL OR ingredient IN (?) ORDER BY (ingredient IS NULL), id',
            [$ingredientSlugs],
            [ArrayParameterType::STRING],
        )->fetchAllNumeric();

        return self::build($rows, $ingredientSlugs);
    }

    /**
     * Les graphes à partir de lignes déjà lues, dans l'ordre « conversions propres puis universelles, par identifiant » : la requête de
     * `forIngredients()` ou toute lecture de la table entière.
     *
     * @param iterable<array{0: ?string, 1: string, 2: string, 3: float|int|string}> $rows `[ingrédient (null : universelle), unité de départ, unité d'arrivée, coefficient]`
     * @param list<string> $ingredientSlugs
     * @return array<string, ConversionGraph>
     */
    public static function build(iterable $rows, array $ingredientSlugs): array
    {
        $universal = [];
        $own = [];
        foreach ($rows as [$ingredient, $start, $end, $coefficient]) {
            $edge = [$start, $end, (float) $coefficient];
            if ($ingredient === null) {
                $universal[] = $edge;
            } else {
                $own[$ingredient][] = $edge;
            }
        }

        $graphs = [];
        foreach ($ingredientSlugs as $slug) {
            $graphs[$slug] = ConversionGraph::fromConversions([...($own[$slug] ?? []), ...$universal]);
        }

        return $graphs;
    }
}
