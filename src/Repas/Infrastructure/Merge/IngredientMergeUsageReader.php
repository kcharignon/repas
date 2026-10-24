<?php

namespace Repas\Repas\Infrastructure\Merge;


use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Domain\Model\IngredientMergeUsage;
use Repas\Repas\Domain\Model\MergeConversion;
use Repas\Repas\Domain\Model\MergeUnitMove;

/**
 * Ce que la fusion de deux ingrédients de l'utilisateur touchera, lu en SQL (ADR 0028, ING-005). Une seule classe pour l'écriture
 * (`IngredientMergePostgreSQLRepository::usageOf()`) et pour l'aperçu (`IngredientMergePostgreSQLLoader`), comme
 * `AdoptionSnapshotReader` pour la migration : les nombres que l'aperçu montre sont ceux que la fusion rencontrera.
 *
 * Une recette est « à l'utilisateur » quand il en est l'auteur, une liste quand il en est le propriétaire. Toute ligne qui n'est pas
 * dans l'une des deux (celle d'un autre utilisateur, ou orpheline) est « à un autre » : la fusion ne la touche pas et s'arrête.
 */
final readonly class IngredientMergeUsageReader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function usageOf(string $userId, string $absorbedSlug, string $keptSlug, string $keptPurchaseUnitSlug): IngredientMergeUsage
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT'
            // Les recettes de l'utilisateur qui utilisent B, et les unités de leurs lignes
            . ' (SELECT count(DISTINCT r.id) FROM recipe_row x JOIN recipe r ON r.id = x.recipe WHERE x.ingredient = :b AND r.author = :u) AS recipes,'
            . " (SELECT string_agg(DISTINCT x.unit, ',') FROM recipe_row x JOIN recipe r ON r.id = x.recipe WHERE x.ingredient = :b AND r.author = :u) AS recipe_units,"
            // Ses listes qui utilisent B, une ligne ou un ingrédient ajouté à la main
            . ' (SELECT count(*) FROM shopping_list l WHERE l.owner = :u AND ('
            . '   EXISTS (SELECT 1 FROM shopping_list_row x WHERE x.shopping_list = l.id AND x.ingredient = :b)'
            . '   OR EXISTS (SELECT 1 FROM shopping_list_ingredient x WHERE x.shopping_list = l.id AND x.ingredient = :b))) AS lists,'
            // Les unités des lignes de B dans ses listes, avec l'unité où elles iront : celle de la ligne de A de la même liste, à défaut
            // l'unité d'achat de A (`IngredientMergePostgreSQLRepository::repoint()` fait le même choix)
            . " (SELECT string_agg(DISTINCT x.unit || '>' || coalesce((SELECT a.unit FROM shopping_list_row a WHERE a.shopping_list = x.shopping_list AND a.ingredient = :a ORDER BY a.id LIMIT 1), CAST(:pa AS VARCHAR)), ',')"
            . '   FROM shopping_list_row x JOIN shopping_list l ON l.id = x.shopping_list AND l.owner = :u WHERE x.ingredient = :b) AS row_moves,'
            // Les unités des ingrédients de B ajoutés à la main : ils iront dans l'unité d'achat de A
            . " (SELECT string_agg(DISTINCT x.unit || '>' || CAST(:pa AS VARCHAR), ',')"
            . '   FROM shopping_list_ingredient x JOIN shopping_list l ON l.id = x.shopping_list AND l.owner = :u WHERE x.ingredient = :b) AS item_moves,'
            // Ses listes envoyées au drive qui utilisent A ou B : immuables, elles bloquent la fusion
            . " (SELECT count(*) FROM shopping_list l WHERE l.owner = :u AND l.status = 'SENT' AND ("
            . '   EXISTS (SELECT 1 FROM shopping_list_row x WHERE x.shopping_list = l.id AND x.ingredient IN (:a, :b))'
            . '   OR EXISTS (SELECT 1 FROM shopping_list_ingredient x WHERE x.shopping_list = l.id AND x.ingredient IN (:a, :b)))) AS sent,'
            // Une ligne de B qui n'est pas à l'utilisateur
            . ' (EXISTS (SELECT 1 FROM recipe_row x WHERE x.ingredient = :b AND NOT EXISTS (SELECT 1 FROM recipe r WHERE r.id = x.recipe AND r.author = :u))'
            . '  OR EXISTS (SELECT 1 FROM shopping_list_row x WHERE x.ingredient = :b AND NOT EXISTS (SELECT 1 FROM shopping_list l WHERE l.id = x.shopping_list AND l.owner = :u))'
            . '  OR EXISTS (SELECT 1 FROM shopping_list_ingredient x WHERE x.ingredient = :b AND NOT EXISTS (SELECT 1 FROM shopping_list l WHERE l.id = x.shopping_list AND l.owner = :u))) AS foreign_use,'
            // Après la fusion, A sera utilisé par l'UNION des recettes et des listes de A et de B : une recette (ou une liste) qui utilisait déjà
            // les deux compte une fois
            . ' (SELECT count(DISTINCT r.id) FROM recipe_row x JOIN recipe r ON r.id = x.recipe WHERE x.ingredient IN (:a, :b) AND r.author = :u) AS result_recipes,'
            . ' (SELECT count(*) FROM shopping_list l WHERE l.owner = :u AND ('
            . '   EXISTS (SELECT 1 FROM shopping_list_row x WHERE x.shopping_list = l.id AND x.ingredient IN (:a, :b))'
            . '   OR EXISTS (SELECT 1 FROM shopping_list_ingredient x WHERE x.shopping_list = l.id AND x.ingredient IN (:a, :b)))) AS result_lists,'
            // Les conversions PROPRES de B, que A peut récupérer : dans un ordre fixe (le planificateur les retrie de toute façon)
            . " (SELECT json_agg(json_build_object('id', c.id, 'start', c.start_unit, 'end', c.end_unit, 'coefficient', c.coefficient) ORDER BY c.start_unit, c.end_unit, c.id)"
            . '   FROM conversion c WHERE c.ingredient = :b) AS absorbed_conversions',
            ['u' => $userId, 'a' => $keptSlug, 'b' => $absorbedSlug, 'pa' => $keptPurchaseUnitSlug],
        );

        $moves = [];
        foreach ([$row['row_moves'], $row['item_moves']] as $aggregate) {
            foreach (self::split($aggregate) as $pair) {
                [$from, $to] = explode('>', $pair, 2);
                $moves[$from . '>' . $to] = new MergeUnitMove($from, $to);
            }
        }
        ksort($moves);

        /** @var list<array{id: string, start: string, end: string, coefficient: int|float}> $conversions */
        $conversions = $row['absorbed_conversions'] === null ? [] : json_decode($row['absorbed_conversions'], true, flags: JSON_THROW_ON_ERROR);

        return new IngredientMergeUsage(
            recipeCount: (int) $row['recipes'],
            listCount: (int) $row['lists'],
            recipeUnitSlugs: self::split($row['recipe_units']),
            listMoves: array_values($moves),
            sentListCount: (int) $row['sent'],
            usedByAnotherUser: (bool) $row['foreign_use'],
            resultRecipeCount: (int) $row['result_recipes'],
            resultListCount: (int) $row['result_lists'],
            absorbedConversions: array_map(
                static fn(array $conversion): MergeConversion => new MergeConversion($conversion['id'], $conversion['start'], $conversion['end'], (float) $conversion['coefficient']),
                $conversions,
            ),
        );
    }

    /**
     * Combien de recettes et de listes de l'utilisateur utilisent chaque ingrédient : pour la fiche d'un ingrédient, les candidats d'une
     * fusion, l'aperçu. Un ingrédient inutilisé est présent, à zéro.
     *
     * @param list<string>|null $slugs les ingrédients demandés, `null` pour tous ceux de la table
     *
     * @return array<string, array{recipes: int, lists: int}> par slug
     */
    public function counts(string $userId, ?array $slugs = null): array
    {
        if ($slugs === []) {
            return [];
        }

        $filter = $slugs === null ? '' : ' AND x.ingredient IN (:slugs)';
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT i.slug, coalesce(rc.n, 0) AS recipes, coalesce(lc.n, 0) AS lists FROM ingredient i'
            . ' LEFT JOIN (SELECT x.ingredient, count(DISTINCT x.recipe) AS n FROM recipe_row x JOIN recipe r ON r.id = x.recipe AND r.author = :u'
            . "   WHERE TRUE$filter GROUP BY x.ingredient) rc ON rc.ingredient = i.slug"
            . ' LEFT JOIN (SELECT x.ingredient, count(DISTINCT x.shopping_list) AS n'
            . '   FROM (SELECT ingredient, shopping_list FROM shopping_list_row UNION SELECT ingredient, shopping_list FROM shopping_list_ingredient) x'
            . "   JOIN shopping_list l ON l.id = x.shopping_list AND l.owner = :u WHERE TRUE$filter GROUP BY x.ingredient) lc ON lc.ingredient = i.slug"
            . ($slugs === null ? '' : ' WHERE i.slug IN (:slugs)'),
            $slugs === null ? ['u' => $userId] : ['u' => $userId, 'slugs' => array_values($slugs)],
            $slugs === null ? [] : ['slugs' => ArrayParameterType::STRING],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['slug']] = ['recipes' => (int) $row['recipes'], 'lists' => (int) $row['lists']];
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private static function split(?string $aggregate): array
    {
        return $aggregate === null || $aggregate === '' ? [] : explode(',', $aggregate);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
