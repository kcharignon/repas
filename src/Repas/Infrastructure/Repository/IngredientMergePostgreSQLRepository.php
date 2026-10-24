<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientMergeRepository;
use Repas\Repas\Domain\Model\IngredientMergeRepointed;
use Repas\Repas\Domain\Model\IngredientMergeUsage;
use Repas\Repas\Domain\Model\UnitFactor;
use Repas\Repas\Infrastructure\Merge\IngredientMergeUsageReader;

/**
 * Les lectures et les écritures en masse de la fusion de deux ingrédients (ADR 0028, ING-005) en SQL direct : un nombre de requêtes
 * constant, jamais une par recette ni par ligne, aucun agrégat hydraté (ni l'identity map de Doctrine, ni les événements, ni la version
 * par `save()` : `UPDATE … SET version = version + 1` explicite, comme la migration d'adoption). Les `UPDATE` ne touchent qu'aux recettes
 * dont l'utilisateur est l'AUTEUR et aux listes dont il est le PROPRIÉTAIRE.
 *
 * Les tables `ingredient`, `conversion`, `recipe*` et `shopping_list*` sont celles du contexte `Repas`. Les choix du drive sont
 * repointés par `Drive` (port `Shared` `DriveIngredientReferences`).
 */
readonly class IngredientMergePostgreSQLRepository implements IngredientMergeRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IngredientMergeUsageReader $usageReader,
    ) {
    }

    public function lock(string $firstSlug, string $secondSlug): void
    {
        // Dans l'ordre alphabétique : deux fusions croisées (A dans B, B dans A) prennent les verrous dans le même ordre
        $this->connection()->executeQuery('SELECT slug FROM ingredient WHERE slug IN (?, ?) ORDER BY slug FOR UPDATE', [$firstSlug, $secondSlug])->free();
    }

    public function usageOf(string $userId, string $absorbedSlug, string $keptSlug, string $keptPurchaseUnitSlug): IngredientMergeUsage
    {
        return $this->usageReader->usageOf($userId, $absorbedSlug, $keptSlug, $keptPurchaseUnitSlug);
    }

    public function recoverConversions(string $absorbedSlug, string $keptSlug, array $newIdBySourceId): void
    {
        if ($newIdBySourceId === []) {
            return;
        }

        // Unités et coefficient sont recopiés par la base depuis la conversion d'origine (aucun aller-retour de flottant) ; la jointure sur
        // `ingredient = B` n'accepte que des conversions propres de l'absorbé
        $copied = (int) $this->connection()->executeStatement(
            'INSERT INTO conversion (id, start_unit, end_unit, coefficient, ingredient)'
            . ' SELECT x.new_id, c.start_unit, c.end_unit, c.coefficient, :a'
            . ' FROM jsonb_to_recordset(CAST(:rows AS jsonb)) AS x(source_id text, new_id text)'
            . ' JOIN conversion c ON c.id = x.source_id AND c.ingredient = :b',
            [
                'a' => $keptSlug,
                'b' => $absorbedSlug,
                'rows' => json_encode(
                    array_map(static fn(string $sourceId, string $newId): array => ['source_id' => $sourceId, 'new_id' => $newId], array_keys($newIdBySourceId), array_values($newIdBySourceId)),
                    JSON_THROW_ON_ERROR,
                ),
            ],
        );

        // Une conversion de B a disparu entre l'analyse et la copie : le bus annule la transaction, la fusion se rejoue
        if ($copied !== count($newIdBySourceId)) {
            throw IngredientException::mergeConflict();
        }
    }

    public function repoint(string $userId, string $absorbedSlug, string $keptSlug, string $keptPurchaseUnitSlug, array $factors): IngredientMergeRepointed
    {
        $connection = $this->connection();
        // PDO refuse un paramètre nommé que la requête n'utilise pas : chaque requête reçoit les siens
        $listParams = ['u' => $userId, 'b' => $absorbedSlug];
        $recipeParams = $listParams + ['a' => $keptSlug];
        $factorParams = $recipeParams + ['pa' => $keptPurchaseUnitSlug, 'factors' => json_encode(
            array_map(static fn(UnitFactor $factor): array => ['from_unit' => $factor->fromUnitSlug, 'to_unit' => $factor->toUnitSlug, 'factor' => $factor->factor], $factors),
            JSON_THROW_ON_ERROR,
        )];

        try {
            // 1. Les recettes : les lignes passent sur A (une recette qui contenait les deux garde ses deux lignes), la version monte
            $recipes = (int) $connection->fetchOne(
                'WITH changed AS (UPDATE recipe_row x SET ingredient = :a FROM recipe r'
                . '   WHERE x.ingredient = :b AND r.id = x.recipe AND r.author = :u RETURNING x.recipe),'
                . ' bumped AS (UPDATE recipe SET version = version + 1 WHERE id IN (SELECT recipe FROM changed) RETURNING id)'
                . ' SELECT count(*) FROM bumped',
                $recipeParams,
            );

            // 2. La version de chaque liste qui utilise B monte une fois, qu'elle ait une ligne, un ingrédient ajouté à la main ou les deux
            $lists = (int) $connection->executeStatement(
                'UPDATE shopping_list l SET version = version + 1 WHERE l.owner = :u AND ('
                . '   EXISTS (SELECT 1 FROM shopping_list_row x WHERE x.shopping_list = l.id AND x.ingredient = :b)'
                . '   OR EXISTS (SELECT 1 FROM shopping_list_ingredient x WHERE x.shopping_list = l.id AND x.ingredient = :b))',
                $listParams,
            );

            // 3. Les lignes de liste. A a déjà sa ligne dans la liste (la première, par identifiant) : elle reçoit la quantité de B ré-exprimée
            // dans SON unité et n'est cochée que si les deux l'étaient. Sinon la ligne de B devient une ligne de A, dans l'unité d'achat de A.
            // Une quantité sans facteur (une unité apparue depuis l'analyse) donne NULL, donc une violation de NOT NULL : la fusion s'annule.
            $connection->executeStatement(
                'WITH factors AS (SELECT f.from_unit, f.to_unit, f.factor FROM jsonb_to_recordset(CAST(:factors AS jsonb)) AS f(from_unit text, to_unit text, factor double precision)),'
                . ' b_rows AS (SELECT x.id, x.shopping_list, x.quantity, x.unit, x.checked,'
                . '     (SELECT a.id FROM shopping_list_row a WHERE a.shopping_list = x.shopping_list AND a.ingredient = :a ORDER BY a.id LIMIT 1) AS a_id'
                . '   FROM shopping_list_row x JOIN shopping_list l ON l.id = x.shopping_list AND l.owner = :u WHERE x.ingredient = :b),'
                . ' b_converted AS (SELECT b.id, b.shopping_list, b.checked, b.a_id, coalesce(a.unit, CAST(:pa AS VARCHAR)) AS target_unit,'
                . '     b.quantity * CASE WHEN b.unit = coalesce(a.unit, CAST(:pa AS VARCHAR)) THEN 1 ELSE f.factor END AS quantity'
                . '   FROM b_rows b LEFT JOIN shopping_list_row a ON a.id = b.a_id'
                . '   LEFT JOIN factors f ON f.from_unit = b.unit AND f.to_unit = coalesce(a.unit, CAST(:pa AS VARCHAR))),'
                . ' per_list AS (SELECT shopping_list, a_id, min(id) AS keep_id, min(target_unit) AS target_unit, bool_and(checked) AS checked,'
                . '     CASE WHEN bool_or(quantity IS NULL) THEN NULL ELSE sum(quantity) END AS quantity'
                . '   FROM b_converted GROUP BY shopping_list, a_id),'
                . ' merged AS (UPDATE shopping_list_row a SET quantity = a.quantity + p.quantity, checked = a.checked AND p.checked'
                . '   FROM per_list p WHERE p.a_id IS NOT NULL AND a.id = p.a_id RETURNING a.id),'
                . ' renamed AS (UPDATE shopping_list_row r SET ingredient = :a, unit = p.target_unit, quantity = p.quantity, checked = p.checked'
                . '   FROM per_list p WHERE p.a_id IS NULL AND r.id = p.keep_id RETURNING r.id),'
                . ' removed AS (DELETE FROM shopping_list_row r WHERE r.id IN (SELECT id FROM b_rows)'
                . '   AND r.id NOT IN (SELECT keep_id FROM per_list WHERE a_id IS NULL) RETURNING r.id)'
                . ' SELECT (SELECT count(*) FROM merged), (SELECT count(*) FROM renamed), (SELECT count(*) FROM removed)',
                $factorParams,
            );

            // 4. Les ingrédients ajoutés à la main : des compteurs de pas entiers dans l'unité d'achat. Même règle, arrondie au pas supérieur
            // (un pas ne disparaît jamais), avec la tolérance du plan d'envoi (1e-9) pour qu'un flottant à peine au-dessus d'un entier ne l'augmente pas.
            $connection->executeStatement(
                'WITH factors AS (SELECT f.from_unit, f.to_unit, f.factor FROM jsonb_to_recordset(CAST(:factors AS jsonb)) AS f(from_unit text, to_unit text, factor double precision)),'
                . ' b_items AS (SELECT x.id, x.shopping_list,'
                . '     CASE WHEN x.unit = CAST(:pa AS VARCHAR) THEN x.quantity ELSE x.quantity * f.factor END AS converted'
                . '   FROM shopping_list_ingredient x JOIN shopping_list l ON l.id = x.shopping_list AND l.owner = :u'
                . '   LEFT JOIN factors f ON f.from_unit = x.unit AND f.to_unit = CAST(:pa AS VARCHAR) WHERE x.ingredient = :b),'
                . ' per_list AS (SELECT b.shopping_list, min(b.id) AS keep_id,'
                . '     CASE WHEN bool_or(b.converted IS NULL) THEN NULL ELSE sum(GREATEST(1, ceil(b.converted - 1e-9))) END AS quantity,'
                . '     (SELECT a.id FROM shopping_list_ingredient a WHERE a.shopping_list = b.shopping_list AND a.ingredient = :a AND a.unit = CAST(:pa AS VARCHAR) ORDER BY a.id LIMIT 1) AS a_id'
                . '   FROM b_items b GROUP BY b.shopping_list),'
                . ' merged AS (UPDATE shopping_list_ingredient a SET quantity = a.quantity + p.quantity'
                . '   FROM per_list p WHERE p.a_id IS NOT NULL AND a.id = p.a_id RETURNING a.id),'
                . ' renamed AS (UPDATE shopping_list_ingredient r SET ingredient = :a, unit = CAST(:pa AS VARCHAR), quantity = p.quantity'
                . '   FROM per_list p WHERE p.a_id IS NULL AND r.id = p.keep_id RETURNING r.id),'
                . ' removed AS (DELETE FROM shopping_list_ingredient r WHERE r.id IN (SELECT id FROM b_items)'
                . '   AND r.id NOT IN (SELECT keep_id FROM per_list WHERE a_id IS NULL) RETURNING r.id)'
                . ' SELECT (SELECT count(*) FROM merged), (SELECT count(*) FROM renamed), (SELECT count(*) FROM removed)',
                $factorParams,
            );
        } catch (NotNullConstraintViolationException) {
            // Une unité est apparue entre l'analyse et l'écriture : le bus annule la transaction, la fusion se rejoue
            throw IngredientException::mergeConflict();
        }

        // Filet : plus aucune ligne ne doit référencer B. Une ligne d'un autre utilisateur est refusée avant (`usageOf()`) ; il ne reste
        // qu'une écriture concurrente, qui poserait une référence sur un ingrédient sur le point d'être supprimé.
        if ($this->referencesTo($absorbedSlug) > 0) {
            throw IngredientException::mergeConflict();
        }

        return new IngredientMergeRepointed($recipes, $lists);
    }

    private function referencesTo(string $slug): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT (SELECT count(*) FROM recipe_row WHERE ingredient = :slug)'
            . ' + (SELECT count(*) FROM shopping_list_row WHERE ingredient = :slug)'
            . ' + (SELECT count(*) FROM shopping_list_ingredient WHERE ingredient = :slug)',
            ['slug' => $slug],
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
