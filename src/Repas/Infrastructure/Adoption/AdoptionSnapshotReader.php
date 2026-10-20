<?php

namespace Repas\Repas\Infrastructure\Adoption;


use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Domain\Model\AdoptionSnapshot;
use Repas\Repas\Domain\Model\AggregateSnapshot;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Infrastructure\Visibility\IngredientVisibility;

/**
 * Lit, en LECTURE SEULE et en quatre requêtes quel que soit le volume, l'empreinte d'un utilisateur côté `Repas` (`AdoptionSnapshot`) :
 * celle que la migration d'adoption compare avant et après (dans sa transaction) et que le rapport à blanc affiche. Partagée par
 * `IngredientAdoptionBulkPostgreSQLRepository` et `IngredientAdoptionReportPostgreSQLLoader`, pour qu'elles disent la même chose.
 *
 * Le contenu d'une recette ou d'une liste est la somme de contrôle de ses lignes `(nom de l'ingrédient, rayon, quantité, unité)` triées :
 * le NOM et le rayon, pas le slug, donc insensible à un repointage vers une copie de même nom et de même rayon. `COLLATE "C"` rend
 * le tri indépendant de la locale de la base ; les quantités sont sommées en `numeric` (exactes, sans dépendre de l'ordre des lignes).
 */
final readonly class AdoptionSnapshotReader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<string>|null $watchedSlugs les ingrédients dont on attend le repointage ; `null` : tous les communs
     */
    public function read(string $userId, ?array $watchedSlugs = null): AdoptionSnapshot
    {
        $connection = $this->connection();
        $params = ['u' => $userId];
        $types = [];
        if ($watchedSlugs === null) {
            // Un ingrédient absent de la table (référence orpheline) n'est jamais « surveillé » : il n'a pas de copie
            $watched = static fn(string $ingredient, string $column): string => sprintf('(%1$s.slug IS NOT NULL AND %2$s)', $ingredient, IngredientVisibility::isCommonSql($ingredient));
        } elseif ($watchedSlugs === []) {
            $watched = static fn(string $ingredient, string $column): string => 'FALSE';
        } else {
            $params['watched'] = $watchedSlugs;
            $types['watched'] = ArrayParameterType::STRING;
            $watched = static fn(string $ingredient, string $column): string => sprintf('(%s IN (:watched))', $column);
        }

        // Une ligne : l'ingrédient désigné (par son nom et son rayon, jamais par son slug) avec sa quantité et son unité
        $tuple = static fn(string $row): string => sprintf("coalesce(i.name, '?') || '|' || coalesce(i.department, '?') || '|' || %1\$s.quantity::text || '|' || %1\$s.unit", $row);

        $rowCounts = $quantitySums = $watchedRowCounts = [];
        foreach ($connection->fetchAllAssociative(
            sprintf(
                'SELECT %s AS t, count(*) AS n, coalesce(sum(rr.quantity::numeric), 0)::text AS s, count(*) FILTER (WHERE %s) AS w'
                . ' FROM recipe_row rr JOIN recipe r ON r.id = rr.recipe LEFT JOIN ingredient i ON i.slug = rr.ingredient WHERE r.author = :u'
                . ' UNION ALL SELECT %s, count(*), coalesce(sum(x.quantity::numeric), 0)::text, count(*) FILTER (WHERE %s)'
                . ' FROM shopping_list_row x JOIN shopping_list l ON l.id = x.shopping_list LEFT JOIN ingredient i ON i.slug = x.ingredient WHERE l.owner = :u'
                . ' UNION ALL SELECT %s, count(*), coalesce(sum(x.quantity::numeric), 0)::text, count(*) FILTER (WHERE %s)'
                . ' FROM shopping_list_ingredient x JOIN shopping_list l ON l.id = x.shopping_list LEFT JOIN ingredient i ON i.slug = x.ingredient WHERE l.owner = :u',
                $connection->quote(AdoptionSnapshot::RECIPE_ROW),
                $watched('i', 'rr.ingredient'),
                $connection->quote(AdoptionSnapshot::SHOPPING_LIST_ROW),
                $watched('i', 'x.ingredient'),
                $connection->quote(AdoptionSnapshot::SHOPPING_LIST_INGREDIENT),
                $watched('i', 'x.ingredient'),
            ),
            $params,
            $types,
        ) as $row) {
            $rowCounts[$row['t']] = (int) $row['n'];
            $quantitySums[$row['t']] = (string) $row['s'];
            $watchedRowCounts[$row['t']] = (int) $row['w'];
        }

        $recipes = [];
        foreach ($connection->fetchAllAssociative(
            sprintf(
                "SELECT r.id, r.version, coalesce(md5(string_agg(%s, E'\\n' ORDER BY %s COLLATE \"C\")), '') AS hash, coalesce(bool_or(%s), FALSE) AS watched"
                . ' FROM recipe r LEFT JOIN recipe_row rr ON rr.recipe = r.id LEFT JOIN ingredient i ON i.slug = rr.ingredient'
                . ' WHERE r.author = :u GROUP BY r.id, r.version ORDER BY r.id',
                $tuple('rr'),
                $tuple('rr'),
                $watched('i', 'rr.ingredient'),
            ),
            $params,
            $types,
        ) as $row) {
            $recipes[$row['id']] = new AggregateSnapshot((int) $row['version'], (string) $row['hash'], '', (bool) $row['watched']);
        }

        // Les deux tables de lignes d'une liste dans le même résumé, repérées par un préfixe (R : ligne, I : ingrédient)
        $shoppingLists = [];
        foreach ($connection->fetchAllAssociative(
            sprintf(
                "SELECT l.id, l.version, l.status, coalesce(l.sent_at::text, '') AS sent_at, coalesce(md5(string_agg(t.tuple, E'\\n' ORDER BY t.tuple COLLATE \"C\")), '') AS hash,"
                . ' coalesce(bool_or(t.watched), FALSE) AS watched'
                . ' FROM shopping_list l LEFT JOIN ('
                . "   SELECT x.shopping_list AS list_id, 'R|' || %s AS tuple, %s AS watched FROM shopping_list_row x LEFT JOIN ingredient i ON i.slug = x.ingredient"
                . '     WHERE x.shopping_list IN (SELECT id FROM shopping_list WHERE owner = :u)'
                . "   UNION ALL SELECT x.shopping_list, 'I|' || %s, %s FROM shopping_list_ingredient x LEFT JOIN ingredient i ON i.slug = x.ingredient"
                . '     WHERE x.shopping_list IN (SELECT id FROM shopping_list WHERE owner = :u)'
                . ' ) t ON t.list_id = l.id WHERE l.owner = :u GROUP BY l.id, l.version, l.status, l.sent_at ORDER BY l.id',
                $tuple('x'),
                $watched('i', 'x.ingredient'),
                $tuple('x'),
                $watched('i', 'x.ingredient'),
            ),
            $params,
            $types,
        ) as $row) {
            $shoppingLists[$row['id']] = new AggregateSnapshot((int) $row['version'], (string) $row['hash'], $row['status'] . '|' . $row['sent_at'], (bool) $row['watched'], $row['status'] === ShoppingListStatus::SENT->value);
        }

        $statistics = (string) $connection->fetchOne('SELECT statistics::text FROM "user" WHERE id = ?', [$userId]);

        return new AdoptionSnapshot($rowCounts, $quantitySums, $watchedRowCounts, $recipes, $shoppingLists, $statistics);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
