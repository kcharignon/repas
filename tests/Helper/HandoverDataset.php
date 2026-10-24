<?php

namespace Repas\Tests\Helper;


use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Repas\Infrastructure\Catalog\BaseIngredientJsonClassification;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\UserBuilder;

/**
 * Un jeu de données qui ressemble à la PRODUCTION d'avant la passation du catalogue (ING-009), avec les VRAIS anciens communs du fichier validé
 * (261 ingrédients sans créateur, 151 « de base » d'après le fichier mais le classement n'est PAS posé : `is_base` vaut faux, comme en production
 * avant la phase 1), une centaine de conversions propres, et :
 *
 *  - le propriétaire du catalogue (administrateur), SANS ingrédient personnel, avec ses recettes, ses listes de tous les statuts dont DEUX ENVOYÉES
 *    au drive et ses choix du drive, tous sur les anciens communs (« de base » ou non) ;
 *  - des comptes MIGRÉS (six par défaut, comme en production) : leurs ingrédients personnels (dont une collision de nom avec un ancien commun,
 *    « tomate »), leurs recettes, listes et choix du drive, leurs 261 copies et leurs alias. La migration est écrite ici EN SQL ENSEMBLISTE (les
 *    mêmes lignes que le cas d'usage d'ING-003, qui a ses propres tests) : la rejouer six fois par le vrai cas d'usage coûte des secondes, et
 *    sa requête dépend des statistiques du planificateur ;
 *  - le COMPTE VIDE : actif, ni recette ni ingrédient, une liste active, une liste ENVOYÉE et deux choix du drive sur des communs dont des « spécifiques » ;
 *  - un compte en attente et un compte désactivé, sans aucune ligne.
 *
 * Valeurs factices uniquement : aucun nom d'ingrédient personnel réel, aucune adresse réelle, aucun numéro de magasin réel.
 */
final class HandoverDataset
{
    public AdoptionDataset $adoption;
    public Connection $connection;
    public string $ownerId = '00000000-0000-4000-8000-0000000000aa';
    public string $emptyId = 'e0000000-0000-4000-8000-0000000000e0';
    public string $pendingId = 'f0000000-0000-4000-8000-0000000000f1';
    public string $disabledId = 'f0000000-0000-4000-8000-0000000000f2';
    /** @var list<string> */
    public array $migratedIds = [];
    /** @var array<string, string> les listes du propriétaire, par statut (la deuxième envoyée : `SENT2`) */
    public array $ownerLists = [];
    /** @var array<string, bool> le classement du fichier */
    public array $classification = [];
    /** @var list<string> les slugs des anciens communs, dans l'ordre du fichier */
    public array $slugs = [];
    /** @var list<string> les anciens communs « spécifiques » (non de base) */
    public array $specificSlugs = [];
    /** @var array<string, list<string>> les ingrédients personnels des comptes migrés, par compte */
    public array $personalSlugs = [];

    private function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataPersister $persister,
    ) {
        $this->adoption = new AdoptionDataset($entityManager, $persister);
        $this->connection = $entityManager->getConnection();
    }

    /**
     * @param int $migratedAccounts le nombre de comptes migrés (six, comme en production ; deux suffisent à la plupart des preuves et coûtent trois fois moins)
     */
    public static function create(EntityManagerInterface $entityManager, DataPersister $persister, int $migratedAccounts = 6): self
    {
        $dataset = new self($entityManager, $persister);
        $dataset->classification = new BaseIngredientJsonClassification(dirname(__DIR__, 2) . '/src/Repas/Infrastructure/Data/base-ingredients.json')->all();
        $dataset->slugs = array_map('strval', array_keys($dataset->classification));
        $dataset->specificSlugs = array_values(array_filter($dataset->slugs, fn(string $slug): bool => !$dataset->classification[$slug]));
        $dataset->createCatalog();
        $dataset->createUsers($migratedAccounts);
        $dataset->createOwner();
        $dataset->createMigratedAccounts();
        $dataset->createEmptyAccount();
        $dataset->migrate();
        // Des statistiques exactes pour le planificateur (sinon elles dépendent du moment où l'autovacuum est passé sur des tables vidées par le
        // test précédent, et un plan de requête change de 0,1 à 6 s)
        foreach (['ingredient', 'conversion', 'ingredient_alias', 'recipe', 'recipe_row', 'shopping_list', 'shopping_list_row', 'shopping_list_ingredient', 'drive_choice', 'drive_choice_product', '"user"'] as $table) {
            $dataset->connection->executeStatement('ANALYZE ' . $table);
        }

        return $dataset;
    }

    /**
     * Un ancien commun du fichier par rang (modulo 261) : la base d'un motif déterministe de lignes.
     */
    public function slug(int $index): string
    {
        return $this->slugs[$index % count($this->slugs)];
    }

    /**
     * Les anciens communs « de base » et « spécifiques », en alternance, pour que chaque compte référence les deux.
     */
    private function mixedSlug(int $index): string
    {
        return $index % 2 === 0 ? $this->slug($index * 3) : $this->specificSlugs[$index % count($this->specificSlugs)];
    }

    private function createCatalog(): void
    {
        foreach (array_unique(array_map(fn(array $row): string => $row['department'], $this->rows())) as $department) {
            $this->adoption->insert('department', ['slug' => $department, 'name' => $department, 'image' => '']);
        }
        foreach ([['gramme', 'gramme', 'g'], ['kilo', 'kilo', 'kg'], ['unite', 'unité', ''], ['tasse', 'tasse', '']] as [$slug, $name, $symbol]) {
            $this->adoption->insert('unit', ['slug' => $slug, 'name' => $name, 'symbol' => $symbol]);
        }
        foreach ($this->rows() as $index => $row) {
            $this->adoption->insert('ingredient', [
                'slug' => $row['slug'],
                'name' => $row['name'],
                'image' => 'images/' . $row['slug'] . '.png',
                'slugged_name' => $row['slug'],
                'department' => $row['department'],
                'default_cooking_unit' => 'gramme',
                'default_purchase_unit' => 'kilo',
                'creator' => null,
                'compatible_units' => '["gramme","kilo"]',
                'adopted_from' => null,
            ]);
            // Des conversions propres : une pour un ingrédient sur trois, deux pour un sur douze (une centaine en tout)
            for ($position = 0; $position < ($index % 12 === 0 ? 2 : ($index % 3 === 0 ? 1 : 0)); ++$position) {
                $this->adoption->insert('conversion', ['id' => UuidGenerator::new(), 'start_unit' => $position === 0 ? 'unite' : 'tasse', 'end_unit' => 'gramme', 'coefficient' => 10.5 + $index, 'ingredient' => $row['slug']]);
            }
        }
        // Une conversion commune à tous (aucun ingrédient) : jamais copiée, jamais touchée
        $this->adoption->insert('conversion', ['id' => UuidGenerator::new(), 'start_unit' => 'kilo', 'end_unit' => 'gramme', 'coefficient' => 1000.0, 'ingredient' => null]);
    }

    private function createUsers(int $migratedAccounts): void
    {
        $this->persister->saveUser(new UserBuilder()->withId($this->ownerId)->isCatalogOwner()->build());
        foreach (range(1, $migratedAccounts) as $n) {
            $id = sprintf('a%d000000-0000-4000-8000-00000000000%d', $n, $n);
            $this->migratedIds[] = $id;
            $this->persister->saveUser(new UserBuilder()->withId($id)->withRecipeAndIngredientStats(3 * $n, $n)->build());
        }
        $this->persister->saveUser(new UserBuilder()->withId($this->emptyId)->build());
        $this->persister->saveUser(new UserBuilder()->withId($this->pendingId)->isPending()->build());
        $this->persister->saveUser(new UserBuilder()->withId($this->disabledId)->isDisabled()->build());
    }

    private function createOwner(): void
    {
        // Quinze recettes de huit lignes chacune, sur des anciens communs de base ou non
        for ($i = 0; $i < 15; ++$i) {
            $this->adoption->recipe($this->ownerId, sprintf('Recette du proprietaire %02d', $i), array_map(fn(int $p): string => $this->mixedSlug($i * 8 + $p), range(0, 7)));
        }
        // Une liste par statut et DEUX envoyées au drive
        foreach (['ACTIVE' => 'ACTIVE', 'PAUSED' => 'PAUSED', 'SENT' => 'SENT', 'SENT2' => 'SENT', 'COMPLETED' => 'COMPLETED'] as $key => $status) {
            $offset = array_search($key, ['ACTIVE', 'PAUSED', 'SENT', 'SENT2', 'COMPLETED'], true) * 11;
            $this->ownerLists[$key] = $this->adoption->shoppingList(
                $this->ownerId,
                $status,
                array_map(fn(int $p): string => $this->mixedSlug($offset + $p), range(0, 9)),
                array_map(fn(int $p): string => $this->mixedSlug($offset + 20 + $p), range(0, 2)),
            );
        }
        // Les choix du drive : deux magasins d'une enseigne et une autre enseigne, avec produits, rangs, contenances et recherches mémorisées
        $leclerc = Retailer::LECLERC->value;
        foreach (range(0, 11) as $index) {
            $slug = $this->slug(40 + $index * 7);
            $this->adoption->choice($this->ownerId, $slug, sprintf('9%03d', $index), $leclerc, '123456', $index % 3 === 0 ? 'recherche memorisee' : null, $index === 1);
        }
        foreach (range(0, 3) as $index) {
            $this->adoption->choice($this->ownerId, $this->slug(40 + $index * 7), sprintf('8%03d', $index), $leclerc, '654321');
        }
        $this->adoption->choice($this->ownerId, $this->slug(40), '7001', AdoptionDataset::SECOND_RETAILER, '777777');
    }

    private function createMigratedAccounts(): void
    {
        foreach ($this->migratedIds as $n => $id) {
            // Leurs ingrédients personnels : le premier a un « tomate » (le nom d'un ancien commun : une collision de slug à l'adoption)
            $personal = [$this->adoption->personalIngredient($id, $n === 0 ? 'Tomate' : 'Ingredient perso A' . $n, 'fruit-et-legume')];
            $personal[] = $this->adoption->personalIngredient($id, 'Ingredient perso B' . $n, 'sauce');
            $this->personalSlugs[$id] = $personal;
            for ($i = 0; $i < 4; ++$i) {
                $this->adoption->recipe($id, sprintf('Recette %d-%d', $n, $i), [$this->mixedSlug($n * 10 + $i), $this->mixedSlug($n * 10 + $i + 3), $personal[$i % 2]]);
            }
            $this->adoption->shoppingList($id, 'ACTIVE', [$this->mixedSlug($n * 5), $this->mixedSlug($n * 5 + 1)], [$personal[1]]);
            if ($n < 2) {
                $this->adoption->shoppingList($id, 'SENT', [$this->mixedSlug($n * 5 + 2), $this->mixedSlug($n * 5 + 3)], []);
            }
            foreach (range(0, 3) as $index) {
                $this->adoption->choice($id, $this->slug($n * 31 + $index * 5), sprintf('6%d%02d', $n, $index), Retailer::LECLERC->value, '123456');
            }
        }
    }

    private function createEmptyAccount(): void
    {
        // Le compte vide : une liste active et une liste ENVOYÉE sur des communs dont des « spécifiques » (le premier est un « spécifique »)
        $this->adoption->shoppingList($this->emptyId, 'ACTIVE', [$this->specificSlugs[0], $this->slug(0), $this->slug(6)], [$this->specificSlugs[1]]);
        $this->adoption->shoppingList($this->emptyId, 'SENT', [$this->specificSlugs[2], $this->slug(9)], []);
        $this->adoption->choice($this->emptyId, $this->specificSlugs[3], '5001', Retailer::LECLERC->value);
        $this->adoption->choice($this->emptyId, $this->slug(12), '5002', Retailer::LECLERC->value);
    }

    /**
     * La migration d'ING-003 de chaque compte migré, en SQL ensembliste : une copie de chaque ancien commun (slug de la politique de domaine, donc
     * `tomate-2<identifiant>` quand le compte a déjà un « tomate »), ses conversions propres, un alias d'adoption par copie, et ses lignes de
     * recettes, de listes et ses choix du drive repointés vers les copies.
     */
    private function migrate(): void
    {
        $policy = new IngredientSlugPolicy();
        $names = $this->connection->fetchAllKeyValue('SELECT slug, name FROM ingredient WHERE creator IS NULL ORDER BY slug');
        foreach ($this->migratedIds as $id) {
            $taken = array_fill_keys($this->connection->fetchFirstColumn('SELECT slug FROM ingredient WHERE slug LIKE ?', ['%' . $id]), true);
            $copies = [];
            foreach ($names as $slug => $name) {
                $copy = $policy->forAdoption((string) $name, $id, static fn(string $candidate): bool => isset($taken[$candidate]));
                $taken[$copy] = true;
                $copies[(string) $slug] = $copy;
            }
            $map = json_encode($copies, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
            $this->connection->executeStatement(
                'INSERT INTO ingredient (slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, creator, compatible_units, adopted_from, is_base)'
                . ' SELECT m.value, c.name, c.image, c.slugged_name, c.department, c.default_cooking_unit, c.default_purchase_unit, :u, c.compatible_units, c.slug, FALSE'
                . ' FROM jsonb_each_text(CAST(:map AS jsonb)) AS m JOIN ingredient c ON c.slug = m.key',
                ['u' => $id, 'map' => $map],
            );
            $this->connection->executeStatement(
                "INSERT INTO ingredient_alias (user_id, alias_slug, ingredient_slug, kind, source, created_at)"
                . " SELECT :u, m.key, m.value, 'adoption', 'migration-essai', '2026-10-20 12:00:00' FROM jsonb_each_text(CAST(:map AS jsonb)) AS m",
                ['u' => $id, 'map' => $map],
            );
            $this->connection->executeStatement(
                'INSERT INTO conversion (id, start_unit, end_unit, coefficient, ingredient)'
                . ' SELECT gen_random_uuid()::text, v.start_unit, v.end_unit, v.coefficient, m.value FROM conversion v JOIN jsonb_each_text(CAST(:map AS jsonb)) AS m ON m.key = v.ingredient',
                ['map' => $map],
            );
            foreach ([
                'UPDATE recipe_row x SET ingredient = m.value FROM jsonb_each_text(CAST(:map AS jsonb)) AS m, recipe r WHERE x.ingredient = m.key AND r.id = x.recipe AND r.author = :u',
                'UPDATE shopping_list_row x SET ingredient = m.value FROM jsonb_each_text(CAST(:map AS jsonb)) AS m, shopping_list l WHERE x.ingredient = m.key AND l.id = x.shopping_list AND l.owner = :u',
                'UPDATE shopping_list_ingredient x SET ingredient = m.value FROM jsonb_each_text(CAST(:map AS jsonb)) AS m, shopping_list l WHERE x.ingredient = m.key AND l.id = x.shopping_list AND l.owner = :u',
                'UPDATE drive_choice x SET ingredient_slug = m.value FROM jsonb_each_text(CAST(:map AS jsonb)) AS m WHERE x.ingredient_slug = m.key AND x.user_id = :u',
            ] as $repoint) {
                $this->connection->executeStatement($repoint, ['u' => $id, 'map' => $map]);
            }
        }
    }

    /**
     * @return list<array{slug: string, name: string, department: string, base: bool}>
     */
    private function rows(): array
    {
        $rows = [];
        foreach (json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/src/Repas/Infrastructure/Data/base-ingredients.json'), true, 8, JSON_THROW_ON_ERROR) as $entry) {
            $rows[] = $entry;
        }

        return $rows;
    }

    /**
     * Un volume de production gonflé pour mesurer la durée, pour le PROPRIÉTAIRE : des recettes, des listes (dont deux envoyées) et des choix du
     * drive avec leurs produits, tous sur les anciens communs du fichier (générés par la base, `generate_series`).
     *
     * @return int le nombre de lignes créées (recettes, lignes et ingrédients de listes, choix du drive)
     */
    public function addOwnerVolume(int $recipes, int $rowsPerRecipe, int $lists, int $rowsPerList, int $choices): int
    {
        $owner = $this->ownerId;
        $catalog = 'WITH cat AS (SELECT slug, row_number() OVER (ORDER BY slug) AS n FROM ingredient WHERE creator IS NULL)';
        $total = (int) $this->connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL');
        $this->connection->executeStatement(
            "INSERT INTO recipe (id, name, slug, serving, author, type, orignal, version) SELECT gen_random_uuid()::text, 'Recette volume ' || g, 'recette-volume-' || g || '-' || ?, 4, ?, 'plat', NULL, 1 FROM generate_series(1, ?) g",
            [$owner, $owner, $recipes],
        );
        $this->connection->executeStatement(
            $catalog . " INSERT INTO recipe_row (id, ingredient, quantity, unit, recipe, position) SELECT gen_random_uuid()::text, cat.slug, 1 + p, 'gramme', r.id, p"
            . " FROM (SELECT id, row_number() OVER (ORDER BY id) AS n FROM recipe WHERE author = ? AND name LIKE 'Recette volume%') r CROSS JOIN generate_series(0, ? - 1) p"
            . ' JOIN cat ON cat.n = ((r.n * 7 + p) % ?) + 1',
            [$owner, $rowsPerRecipe, $total],
        );
        $this->connection->executeStatement(
            "INSERT INTO shopping_list (id, owner, created_at, status, name, sent_at, version) SELECT gen_random_uuid()::text, ?, '2026-10-01 10:00:00', CASE WHEN g <= 2 THEN 'SENT' WHEN g = 3 THEN 'ACTIVE' ELSE 'COMPLETED' END, 'Liste volume ' || g,"
            . " CASE WHEN g <= 2 THEN '2026-10-10 12:00:00'::timestamp ELSE NULL END, 5 FROM generate_series(1, ?) g",
            [$owner, $lists],
        );
        $this->connection->executeStatement(
            $catalog . " INSERT INTO shopping_list_row (id, shopping_list, ingredient, quantity, unit, checked) SELECT gen_random_uuid()::text, l.id, cat.slug, 2.5 + p, 'gramme', (p % 2 = 0)"
            . " FROM (SELECT id, row_number() OVER (ORDER BY id) AS n FROM shopping_list WHERE owner = ? AND name LIKE 'Liste volume%') l CROSS JOIN generate_series(0, ? - 1) p"
            . ' JOIN cat ON cat.n = ((l.n * 11 + p) % ?) + 1',
            [$owner, $rowsPerList, $total],
        );
        $this->connection->executeStatement(
            $catalog . " INSERT INTO shopping_list_ingredient (id, shopping_list, ingredient, unit, quantity) SELECT gen_random_uuid()::text, l.id, cat.slug, 'gramme', 1 + p"
            . " FROM (SELECT id, row_number() OVER (ORDER BY id) AS n FROM shopping_list WHERE owner = ? AND name LIKE 'Liste volume%') l CROSS JOIN generate_series(0, ? - 1) p"
            . ' JOIN cat ON cat.n = ((l.n * 13 + p) % ?) + 1',
            [$owner, $rowsPerList, $total],
        );
        $this->connection->executeStatement(
            $catalog . " INSERT INTO drive_choice (id, user_id, retailer, store_id, ingredient_slug, kind, search_query, updated_at) SELECT gen_random_uuid()::text, ?, ?, '999999', cat.slug, 'PRODUCTS',"
            . " CASE WHEN g % 3 = 0 THEN 'recherche ' || g ELSE NULL END, '2026-10-18 10:00:00' FROM generate_series(1, ?) g JOIN cat ON cat.n = (g % ?) + 1",
            [$owner, Retailer::LECLERC->value, $choices, $total],
        );
        $this->connection->executeStatement(
            "INSERT INTO drive_choice_product (choice_id, product_id, verdict, rank, pack_size_value, pack_size_unit_slug) SELECT c.id, gen_random_uuid()::text, CASE WHEN v.n < 3 THEN 'ACCEPTED' ELSE 'REJECTED' END, CASE WHEN v.n < 3 THEN v.n END, NULL, NULL"
            . " FROM drive_choice c, generate_series(1, 3) v(n) WHERE c.user_id = ? AND c.store_id = '999999'",
            [$owner],
        );
        foreach (['recipe', 'recipe_row', 'shopping_list', 'shopping_list_row', 'shopping_list_ingredient', 'drive_choice', 'drive_choice_product'] as $table) {
            $this->connection->executeStatement('ANALYZE ' . $table);
        }

        return $recipes * $rowsPerRecipe + $lists * $rowsPerList * 2 + $choices;
    }

    /**
     * Pose le classement (la phase 1) directement en SQL, pour les tests qui commencent après elle.
     */
    public function markBase(): void
    {
        foreach ($this->slugs as $slug) {
            if ($this->classification[$slug]) {
                $this->connection->executeStatement('UPDATE ingredient SET is_base = TRUE WHERE slug = ? AND creator IS NULL', [$slug]);
            }
        }
    }

    /**
     * Tout ce qui devrait rester identique par la passation, table par table, SAUF `ingredient.creator` : la somme de contrôle de chaque table
     * (pour `ingredient`, toutes les colonnes sauf `creator`, et `is_base` pour qui le demande ; pour `user`, sans les statistiques du propriétaire).
     *
     * @return array<string, string>
     */
    public function hashesWithoutCreator(bool $withMarkers = true): array
    {
        $hashes = [];
        // TOUTES les tables du schéma, sauf `ingredient` et `user`, qui ont leur propre somme ci-dessous
        foreach ($this->connection->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' AND table_name NOT IN ('ingredient', 'user', 'doctrine_migration_versions') ORDER BY table_name") as $table) {
            $hashes[$table] = (string) $this->connection->fetchOne(sprintf("SELECT md5(coalesce(string_agg(t::text, ',' ORDER BY t::text COLLATE \"C\"), '')) FROM %s t", $this->connection->quoteSingleIdentifier($table)));
        }
        $markers = $withMarkers ? ', is_base' : '';
        $hashes['ingredient'] = (string) $this->connection->fetchOne(
            "SELECT md5(coalesce(string_agg(concat_ws('|', slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, compatible_units::text, coalesce(adopted_from, '∅')"
            . $markers . "), ',' ORDER BY slug COLLATE \"C\"), '')) FROM ingredient",
        );
        $hashes['ingredient_creators_except_old_commons'] = (string) $this->connection->fetchOne(
            "SELECT md5(coalesce(string_agg(slug || '=' || creator, ',' ORDER BY slug COLLATE \"C\"), '')) FROM ingredient WHERE creator IS NOT NULL AND (adopted_from IS NOT NULL OR slug ~ '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\$')",
        );
        $hashes['user_without_owner_statistics'] = (string) $this->connection->fetchOne(
            "SELECT md5(coalesce(string_agg(CASE WHEN id = ? THEN (to_jsonb(u) - 'statistics')::text ELSE u::text END, ',' ORDER BY id), '')) FROM \"user\" u",
            [$this->ownerId],
        );

        return $hashes;
    }

    public function slugForUser(string $name, string $userId): string
    {
        return StringTool::slugify($name . $userId);
    }
}
