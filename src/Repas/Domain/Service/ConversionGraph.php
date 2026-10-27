<?php

namespace Repas\Repas\Domain\Service;


/**
 * Le graphe de conversion d'un ingrédient : ses unités sont les sommets, chaque conversion une arête dans les deux sens (le
 * coefficient, puis son inverse). C'est ce que `ConversionService` parcourt pour convertir une quantité ; il est ici une valeur pure,
 * faite de slugs, pour que d'autres que lui (la fusion de deux ingrédients, ING-005) posent la même question « cette unité se
 * convertit-elle en celle-ci ? » SANS hydrater d'ingrédient ni d'unité, et obtiennent exactement la même réponse que l'application.
 *
 * Le chemin est cherché en largeur : le moins d'étapes, le produit des coefficients le long du premier chemin trouvé.
 */
final readonly class ConversionGraph
{
    /**
     * @param array<string, list<array{to: string, weight: float}>> $edges les arêtes qui partent de chaque unité (`edgesOf()`)
     */
    public function __construct(private array $edges)
    {
    }

    /**
     * Les arêtes d'un ensemble de conversions : pour chacune, `début -> fin` au poids du coefficient et `fin -> début` au poids inverse.
     * L'ordre des conversions est celui des arêtes : il départage deux chemins de même longueur.
     *
     * @param iterable<array{0: string, 1: string, 2: float}> $conversions `[unité de départ, unité d'arrivée, coefficient]`
     * @return array<string, list<array{to: string, weight: float}>>
     */
    public static function edgesOf(iterable $conversions): array
    {
        $edges = [];
        foreach ($conversions as [$start, $end, $coefficient]) {
            $edges[$start][] = ['to' => $end, 'weight' => $coefficient];
            $edges[$end][] = ['to' => $start, 'weight' => 1 / $coefficient];
        }

        return $edges;
    }

    /**
     * @param iterable<array{0: string, 1: string, 2: float}> $conversions `[unité de départ, unité d'arrivée, coefficient]`
     */
    public static function fromConversions(iterable $conversions): self
    {
        return new self(self::edgesOf($conversions));
    }

    /**
     * Le même graphe avec une conversion de plus (`début -> fin` au poids du coefficient, `fin -> début` à son inverse) : le graphe d'un
     * ingrédient qui récupère la conversion d'un autre (fusion, `IngredientMergePlanner`). Le graphe d'origine ne change pas.
     */
    public function with(string $start, string $end, float $coefficient): self
    {
        $edges = $this->edges;
        foreach (self::edgesOf([[$start, $end, $coefficient]]) as $unit => $unitEdges) {
            $edges[$unit] = [...($edges[$unit] ?? []), ...$unitEdges];
        }

        return new self($edges);
    }

    /**
     * Le facteur qui convertit une quantité de `$start` en `$target`, ou `null` si aucune suite de conversions ne les relie.
     */
    public function coefficient(string $start, string $target): ?float
    {
        return self::coefficientIn($this->edges, $start, $target);
    }

    /**
     * Une suite de conversions relie-t-elle `$start` à `$target` ? (une unité se relie toujours à elle-même) C'est la question de la lecture de la
     * recette (`convertible`) et du rapport de santé des conversions : une seule définition.
     */
    public function connects(string $start, string $target): bool
    {
        return $this->coefficient($start, $target) !== null;
    }

    /**
     * Recherche en largeur dans les arêtes d'un graphe (`edgesOf()`).
     *
     * @param array<string, list<array{to: string, weight: float}>> $edges
     */
    public static function coefficientIn(array $edges, string $start, string $target): ?float
    {
        // La file d'attente contient des paires : [unité courante, coefficient cumulé]
        $queue = [[$start, 1.0]];
        $visited = [$start => true];

        while ($queue !== []) {
            [$current, $coefficient] = array_shift($queue);

            if ($current === $target) {
                return $coefficient;
            }

            foreach ($edges[$current] ?? [] as $edge) {
                if (!isset($visited[$edge['to']])) {
                    $visited[$edge['to']] = true;
                    $queue[] = [$edge['to'], $coefficient * $edge['weight']];
                }
            }
        }

        return null;
    }
}
