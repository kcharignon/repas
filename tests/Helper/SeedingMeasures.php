<?php

namespace Repas\Tests\Helper;


/**
 * Les mesures de l'amorçage (ING-008), calculées INDÉPENDAMMENT du code de production : directement en SQL sur la base de test. À utiliser
 * dans une classe qui a `$this->dataset` (un `SeedingDataset`).
 *
 * @property SeedingDataset $dataset
 */
trait SeedingMeasures
{
    /**
     * Le contenu de toutes les recettes et listes d'un compte, vu par le nom et le rayon des ingrédients (jamais le slug), trié.
     *
     * @return list<string>
     */
    private function contentOf(string $userId): array
    {
        $connection = $this->dataset->adoption->connection;
        $rows = [
            ...$connection->fetchFirstColumn("SELECT 'R|' || o.id || '|' || i.name || '|' || i.department || '|' || x.quantity::text || '|' || x.unit || '|' || x.position FROM recipe_row x JOIN recipe o ON o.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient WHERE o.author = :u", ['u' => $userId]),
            ...$connection->fetchFirstColumn("SELECT 'L|' || o.id || '|' || o.status || '|' || coalesce(o.sent_at::text, '') || '|' || i.name || '|' || i.department || '|' || x.quantity::text || '|' || x.unit || '|' || x.checked::text FROM shopping_list_row x JOIN shopping_list o ON o.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE o.owner = :u", ['u' => $userId]),
            ...$connection->fetchFirstColumn("SELECT 'I|' || o.id || '|' || i.name || '|' || i.department || '|' || x.quantity::text || '|' || x.unit FROM shopping_list_ingredient x JOIN shopping_list o ON o.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE o.owner = :u", ['u' => $userId]),
        ];
        sort($rows);

        return $rows;
    }

    /**
     * Les choix du drive d'un compte avec leurs produits, vus par le nom de l'ingrédient (jamais le slug), triés.
     *
     * @return list<string>
     */
    private function driveContentOf(string $userId): array
    {
        $rows = $this->dataset->adoption->connection->fetchFirstColumn(
            "SELECT c.id || '|' || i.name || '|' || c.retailer || '|' || c.store_id || '|' || c.kind || '|' || coalesce(c.search_query, '') || '|' || c.updated_at::text || '|' ||"
            . " coalesce((SELECT string_agg(p.product_id || ':' || p.verdict || ':' || coalesce(p.rank::text, '') || ':' || coalesce(p.pack_size_value::text, '') || ':' || coalesce(p.pack_size_unit_slug, ''), ',' ORDER BY p.product_id) FROM drive_choice_product p WHERE p.choice_id = c.id), '')"
            . ' FROM drive_choice c JOIN ingredient i ON i.slug = c.ingredient_slug WHERE c.user_id = :u',
            ['u' => $userId],
        );
        sort($rows);

        return $rows;
    }

    /**
     * @return array<string, int> les versions des recettes et des listes d'un compte, par identifiant
     */
    private function versionsOf(string $userId): array
    {
        $connection = $this->dataset->adoption->connection;

        return [
            ...$connection->fetchAllKeyValue('SELECT id, version FROM recipe WHERE author = ? ORDER BY id', [$userId]),
            ...$connection->fetchAllKeyValue('SELECT id, version FROM shopping_list WHERE owner = ? ORDER BY id', [$userId]),
        ];
    }

    /**
     * Les lignes d'un compte (recettes, listes, choix du drive) qui désignent encore un ingrédient qui n'est PAS à lui, par table.
     *
     * @return array<string, int>
     */
    private function linesOnAnIngredientOfSomeoneElse(string $userId): array
    {
        $connection = $this->dataset->adoption->connection;
        $count = static fn(string $from): int => (int) $connection->fetchOne("SELECT count(*) $from AND i.creator IS DISTINCT FROM :u", ['u' => $userId]);

        return [
            'recipe_row' => $count('FROM recipe_row x JOIN recipe o ON o.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient WHERE o.author = :u'),
            'shopping_list_row' => $count('FROM shopping_list_row x JOIN shopping_list o ON o.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE o.owner = :u'),
            'shopping_list_ingredient' => $count('FROM shopping_list_ingredient x JOIN shopping_list o ON o.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE o.owner = :u'),
            'drive_choice' => $count('FROM drive_choice x JOIN ingredient i ON i.slug = x.ingredient_slug WHERE x.user_id = :u'),
        ];
    }

    /**
     * Une somme de contrôle du catalogue (les ingrédients sans créateur ou au propriétaire, avec leurs conversions) : l'amorçage ne le modifie jamais.
     */
    private function catalogHash(): string
    {
        $connection = $this->dataset->adoption->connection;
        $owner = $this->dataset->ownerId;

        return md5(
            implode("\n", $connection->fetchFirstColumn('SELECT t::text FROM ingredient t WHERE creator IS NULL OR creator = ? ORDER BY slug', [$owner]))
            . '--' . implode("\n", $connection->fetchFirstColumn('SELECT t::text FROM conversion t WHERE ingredient IS NULL OR ingredient IN (SELECT slug FROM ingredient WHERE creator IS NULL OR creator = ?) ORDER BY id', [$owner])),
        );
    }

    /**
     * Les copies d'un compte : les ingrédients qu'il a reçus (`adopted_from` renseigné), par slug d'origine.
     *
     * @return array<string, string> slug de la copie, par slug de l'origine
     */
    private function copiesOf(string $userId): array
    {
        return $this->dataset->adoption->connection->fetchAllKeyValue(
            "SELECT a.alias_slug, a.ingredient_slug FROM ingredient_alias a JOIN ingredient o ON o.slug = a.ingredient_slug AND o.creator = a.user_id WHERE a.user_id = ? AND a.kind = 'adoption' ORDER BY a.alias_slug",
            [$userId],
        );
    }
}
