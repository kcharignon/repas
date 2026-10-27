<?php

namespace Repas\Repas\Infrastructure\Loader;


use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\ConversionHealthLoader;
use Repas\Repas\Application\Query\GetConversionHealth\ConversionHealthAccountDTO;
use Repas\Repas\Application\Query\GetConversionHealth\ConversionHealthDTO;
use Repas\Repas\Application\Query\GetConversionHealth\ReferenceConversionDTO;
use Repas\Repas\Application\Query\GetConversionHealth\RepeatedUniversalConversionDTO;
use Repas\Repas\Domain\Model\ReferenceUniversalConversions;
use Repas\Repas\Infrastructure\Conversion\IngredientConversionGraphs;

/**
 * Le rapport de santé des conversions, sur la base, en LECTURE SEULE : cinq lectures (les conversions, les comptes, les ingrédients, les lignes de
 * recettes regroupées par auteur, ingrédient et unité, les recettes par auteur) quel que soit le nombre de comptes, d'ingrédients ou de recettes,
 * aucune hydratation de modèle. « Se convertit en l'unité d'achat » est jugé par le même `ConversionGraph` que la planification et que le
 * `convertible` de la lecture d'une recette (`IngredientConversionGraphs`).
 *
 * Aucun nom d'ingrédient ni de recette n'est lu : seulement des slugs, qui ne servent qu'à relier les lignes à leur ingrédient.
 */
final readonly class ConversionHealthPostgreSQLLoader implements ConversionHealthLoader
{
    /** Deux coefficients qui ne diffèrent que par l'arrondi des flottants sont le même. */
    private const float TOLERANCE = 1e-9;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function report(): ConversionHealthDTO
    {
        $connection = $this->entityManager->getConnection();

        $conversions = $connection->fetchAllAssociative('SELECT id, ingredient, start_unit, end_unit, coefficient FROM conversion ORDER BY (ingredient IS NULL), id');
        $universal = array_values(array_filter($conversions, static fn(array $row): bool => $row['ingredient'] === null));
        $own = array_values(array_filter($conversions, static fn(array $row): bool => $row['ingredient'] !== null));

        [$reference, $extra] = $this->universal($universal);

        $ingredients = $connection->fetchAllAssociative('SELECT slug, creator, default_cooking_unit, default_purchase_unit FROM ingredient');
        $graphs = IngredientConversionGraphs::build(
            array_map(static fn(array $row): array => [$row['ingredient'], $row['start_unit'], $row['end_unit'], $row['coefficient']], $conversions),
            array_column($ingredients, 'slug'),
        );

        $users = $connection->fetchAllAssociative('SELECT id, status FROM "user" ORDER BY id');
        $accounts = [];
        foreach ($users as $index => $user) {
            $accounts[$user['id']] = ['rank' => $index + 1, 'status' => $user['status'], 'ingredients' => 0, 'ingredientsNotConvertible' => 0, 'recipes' => 0, 'rows' => 0, 'rowsNotConvertible' => 0];
        }

        $unattachedIngredients = 0;
        $purchaseUnits = [];
        foreach ($ingredients as $ingredient) {
            $purchaseUnits[$ingredient['slug']] = $ingredient['default_purchase_unit'];
            $convertible = $graphs[$ingredient['slug']]->connects($ingredient['default_cooking_unit'], $ingredient['default_purchase_unit']);
            if (isset($accounts[$ingredient['creator'] ?? ''])) {
                ++$accounts[$ingredient['creator']]['ingredients'];
                $accounts[$ingredient['creator']]['ingredientsNotConvertible'] += $convertible ? 0 : 1;
            } elseif (!$convertible) {
                ++$unattachedIngredients;
            }
        }

        foreach ($connection->fetchAllAssociative('SELECT author, count(*) AS n FROM recipe GROUP BY author') as $recipes) {
            if (isset($accounts[$recipes['author']])) {
                $accounts[$recipes['author']]['recipes'] += (int) $recipes['n'];
            }
        }

        // Une ligne de recette dont l'ingrédient n'existe plus (aucune clé étrangère) n'est jugée par personne : elle n'est pas comptée
        $unattachedRows = 0;
        foreach ($connection->fetchAllAssociative('SELECT r.author AS author, rr.ingredient AS ingredient, rr.unit AS unit, count(*) AS n FROM recipe_row rr INNER JOIN recipe r ON r.id = rr.recipe GROUP BY r.author, rr.ingredient, rr.unit') as $row) {
            if (!isset($purchaseUnits[$row['ingredient']])) {
                continue;
            }
            $count = (int) $row['n'];
            $notConvertible = $graphs[$row['ingredient']]->connects($row['unit'], $purchaseUnits[$row['ingredient']]) ? 0 : $count;
            if (isset($accounts[$row['author']])) {
                $accounts[$row['author']]['rows'] += $count;
                $accounts[$row['author']]['rowsNotConvertible'] += $notConvertible;
            } else {
                $unattachedRows += $notConvertible;
            }
        }

        return new ConversionHealthDTO(
            reference: $reference,
            extraUniversal: $extra,
            repeated: $this->repeated($universal, $own),
            accounts: array_map(
                static fn(string $id, array $account): ConversionHealthAccountDTO => new ConversionHealthAccountDTO(
                    $account['rank'],
                    IngredientSeedingReportPostgreSQLLoader::referenceOf($id),
                    $account['status'],
                    $account['ingredients'],
                    $account['ingredientsNotConvertible'],
                    $account['recipes'],
                    $account['rows'],
                    $account['rowsNotConvertible'],
                ),
                array_keys($accounts),
                array_values($accounts),
            ),
            unattachedIngredientsNotConvertible: $unattachedIngredients,
            unattachedRecipeRowsNotConvertible: $unattachedRows,
        );
    }

    /**
     * Chaque ligne de référence : une ligne universelle relie-t-elle sa paire (dans un sens ou dans l'autre), avec le coefficient attendu ? Et les
     * universelles qui ne sont dans aucune paire de la référence.
     *
     * @param list<array<string, mixed>> $universal
     * @return array{0: list<ReferenceConversionDTO>, 1: list<string>}
     */
    private function universal(array $universal): array
    {
        $reference = [];
        $referencePairs = [];
        foreach (ReferenceUniversalConversions::LINES as [, $start, $end, $coefficient]) {
            $referencePairs[self::pair($start, $end)] = true;
            $expected = ReferenceUniversalConversions::label($start, $end, $coefficient);

            $found = null;
            $same = false;
            foreach ($universal as $line) {
                if (self::pair($line['start_unit'], $line['end_unit']) !== self::pair($start, $end)) {
                    continue;
                }
                $found ??= ReferenceUniversalConversions::label($line['start_unit'], $line['end_unit'], (float) $line['coefficient']);
                $inSameDirection = $line['start_unit'] === $start;
                $factor = $inSameDirection ? (float) $line['coefficient'] : 1 / (float) $line['coefficient'];
                $same = $same || abs($factor - $coefficient) <= self::TOLERANCE * $coefficient;
            }
            $reference[] = new ReferenceConversionDTO($expected, $found === null ? ReferenceConversionDTO::MISSING : ($same ? ReferenceConversionDTO::PRESENT : ReferenceConversionDTO::OTHER_COEFFICIENT), $found);
        }

        $extra = [];
        foreach ($universal as $line) {
            if (!isset($referencePairs[self::pair($line['start_unit'], $line['end_unit'])])) {
                $extra[] = ReferenceUniversalConversions::label($line['start_unit'], $line['end_unit'], (float) $line['coefficient']);
            }
        }
        sort($extra);

        return [$reference, array_values(array_unique($extra))];
    }

    /**
     * Les conversions propres qui répètent une paire UNIVERSELLE (une paire de la référence, même si sa ligne manque en base, ou celle d'une
     * universelle en base, dans un sens ou dans l'autre) ou qui portent l'identifiant d'une ligne de référence : une ligne détournée a pu changer de
     * paire et de coefficient quand l'ancien `PUT` d'un ingrédient l'a réécrite. Regroupées par paire (telle qu'écrite) et coefficient ;
     * `hijacked` compte celles qui portent l'identifiant d'une ligne de référence.
     *
     * @param list<array<string, mixed>> $universal
     * @param list<array<string, mixed>> $own
     * @return list<RepeatedUniversalConversionDTO>
     */
    private function repeated(array $universal, array $own): array
    {
        $universalPairs = [];
        foreach (ReferenceUniversalConversions::LINES as [, $start, $end]) {
            $universalPairs[self::pair($start, $end)] = true;
        }
        foreach ($universal as $line) {
            $universalPairs[self::pair($line['start_unit'], $line['end_unit'])] = true;
        }
        $referenceIds = array_flip(ReferenceUniversalConversions::ids());

        $groups = [];
        foreach ($own as $line) {
            $hijacked = isset($referenceIds[$line['id']]);
            if (!$hijacked && !isset($universalPairs[self::pair($line['start_unit'], $line['end_unit'])])) {
                continue;
            }
            $key = sprintf("%s\0%s\0%s", $line['start_unit'], $line['end_unit'], ReferenceUniversalConversions::number((float) $line['coefficient']));
            $groups[$key] ??= ['start' => $line['start_unit'], 'end' => $line['end_unit'], 'coefficient' => (float) $line['coefficient'], 'count' => 0, 'hijacked' => 0];
            ++$groups[$key]['count'];
            $groups[$key]['hijacked'] += $hijacked ? 1 : 0;
        }
        ksort($groups);

        return array_values(array_map(
            static fn(array $group): RepeatedUniversalConversionDTO => new RepeatedUniversalConversionDTO(
                ReferenceUniversalConversions::label($group['start'], $group['end'], $group['coefficient']),
                $group['count'],
                $group['hijacked'],
            ),
            $groups,
        ));
    }

    /**
     * Une paire d'unités SANS sens : « kilo > gramme » et « gramme > kilo » sont la même.
     */
    private static function pair(string $start, string $end): string
    {
        return $start < $end ? $start . "\0" . $end : $end . "\0" . $start;
    }
}
