<?php

namespace Repas\Tests\Helper;


use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\User\Domain\Model\User;

/**
 * Un jeu de données qui ressemble à la production avant la migration d'adoption des communs (ING-003) : un catalogue de communs (certains
 * avec des conversions propres, un dont le nom finit par un symbole, un dont le nom est aussi celui d'un ingrédient personnel), un
 * administrateur, un gros compte (ingrédients personnels dont une collision de slug, recettes, listes de tous les statuts dont une
 * envoyée, choix du drive sur plusieurs magasins et une autre enseigne, avec produits, rangs, contenances et recherches mémorisées), un
 * compte qui n'a que des recettes copiées, un compte avec un seul ingrédient personnel, un compte vide (une liste et un choix, ni recette
 * ni ingrédient) et un compte désactivé qui a des recettes.
 *
 * Tout est écrit en SQL direct (rapide, et les tests de volume réutilisent les mêmes tables). Valeurs factices uniquement : aucun nom
 * réel d'ingrédient personnel, aucun numéro de magasin réel, aucune adresse réelle.
 */
final class AdoptionDataset
{
    public const string SECOND_RETAILER = 'testretailer';

    public Connection $connection;

    /** @var list<string> les slugs des communs, triés */
    public array $commons = [];
    /** @var array<string, string> le nom de chaque commun, par slug */
    public array $commonNames = [];

    public string $adminId;
    public string $bigId;
    public string $copierId;
    public string $singleId;
    public string $emptyId;
    public string $disabledId;

    /** @var array<string, string> les identifiants des listes du gros compte, par statut */
    public array $bigLists = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataPersister $persister,
    ) {
        $this->connection = $entityManager->getConnection();
    }

    /**
     * @param int $commonCount nombre de communs du catalogue (au moins 8 : les communs nommés d'abord)
     */
    public static function create(EntityManagerInterface $entityManager, DataPersister $persister, int $commonCount = 12): self
    {
        $dataset = new self($entityManager, $persister);
        $dataset->createCatalog($commonCount);
        $dataset->createUsers();
        $dataset->createAdmin();
        $dataset->createBig();
        $dataset->createCopier();
        $dataset->createSingle();
        $dataset->createEmpty();
        $dataset->createDisabled();

        return $dataset;
    }

    /**
     * Un volume de production gonflé pour mesurer la durée : un catalogue de communs (dont des conversions propres) et, pour un compte
     * concerné, des recettes, des listes (dont deux envoyées) et des choix du drive, générés par la base (`generate_series`).
     *
     * @return array{commons: int, lines: int} le nombre de communs et le nombre de lignes à repointer créées pour ce compte
     */
    public static function createBulk(EntityManagerInterface $entityManager, DataPersister $persister, string $userId, int $recipes, int $rowsPerRecipe, int $lists, int $rowsPerList, int $choices, bool $withCatalog = true): array
    {
        $dataset = new self($entityManager, $persister);
        $connection = $dataset->connection;
        $commons = 262;

        if ($withCatalog) {
            $connection->executeStatement(
                "INSERT INTO ingredient (slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, creator, compatible_units, adopted_from)"
                . " SELECT 'commun-' || lpad(g::text, 3, '0'), 'Commun ' || lpad(g::text, 3, '0'), 'images/x.png', 'commun-' || lpad(g::text, 3, '0'), 'rayon-' || (g % 12), 'gramme', 'kilo', NULL, '[\"gramme\",\"kilo\"]', NULL"
                . ' FROM generate_series(1, ?) g',
                [$commons],
            );
            $connection->executeStatement(
                "INSERT INTO conversion (id, start_unit, end_unit, coefficient, ingredient)"
                . " SELECT gen_random_uuid()::text, 'unite', 'gramme', 10 + g, 'commun-' || lpad((((g * 2) % ?) + 1)::text, 3, '0') FROM generate_series(1, 105) g",
                [$commons],
            );
        }
        $connection->executeStatement(
            "INSERT INTO recipe (id, name, slug, serving, author, type, orignal, version)"
            . " SELECT gen_random_uuid()::text, 'Recette ' || g, 'recette-' || g || '-' || ?, 4, ?, 'plat', NULL, 1 FROM generate_series(1, ?) g",
            [$userId, $userId, $recipes],
        );
        $connection->executeStatement(
            "INSERT INTO recipe_row (id, ingredient, quantity, unit, recipe, position)"
            . " SELECT gen_random_uuid()::text, 'commun-' || lpad((((r.n * 7 + p) % ?) + 1)::text, 3, '0'), 1 + p, 'gramme', r.id, p"
            . ' FROM (SELECT id, row_number() OVER (ORDER BY id) AS n FROM recipe WHERE author = ?) r, generate_series(0, ? - 1) p',
            [$commons, $userId, $rowsPerRecipe],
        );
        $connection->executeStatement(
            "INSERT INTO shopping_list (id, owner, created_at, status, name, sent_at, version)"
            . " SELECT gen_random_uuid()::text, ?, '2026-10-01 10:00:00', CASE WHEN g <= 2 THEN 'SENT' WHEN g = 3 THEN 'ACTIVE' ELSE 'COMPLETED' END, 'Liste ' || g,"
            . " CASE WHEN g <= 2 THEN '2026-10-10 12:00:00'::timestamp ELSE NULL END, 5 FROM generate_series(1, ?) g",
            [$userId, $lists],
        );
        $connection->executeStatement(
            "INSERT INTO shopping_list_row (id, shopping_list, ingredient, quantity, unit, checked)"
            . " SELECT gen_random_uuid()::text, l.id, 'commun-' || lpad((((l.n * 11 + p) % ?) + 1)::text, 3, '0'), 2.5 + p, 'gramme', (p % 2 = 0)"
            . ' FROM (SELECT id, row_number() OVER (ORDER BY id) AS n FROM shopping_list WHERE owner = ?) l, generate_series(0, ? - 1) p',
            [$commons, $userId, $rowsPerList],
        );
        $connection->executeStatement(
            "INSERT INTO shopping_list_ingredient (id, shopping_list, ingredient, unit, quantity)"
            . " SELECT gen_random_uuid()::text, l.id, 'commun-' || lpad((((l.n * 13 + p) % ?) + 1)::text, 3, '0'), 'gramme', 1 + p"
            . ' FROM (SELECT id, row_number() OVER (ORDER BY id) AS n FROM shopping_list WHERE owner = ?) l, generate_series(0, ? - 1) p',
            [$commons, $userId, $rowsPerList],
        );
        $connection->executeStatement(
            "INSERT INTO drive_choice (id, user_id, retailer, store_id, ingredient_slug, kind, search_query, updated_at)"
            . " SELECT gen_random_uuid()::text, ?, ?, '123456', 'commun-' || lpad(((g % ?) + 1)::text, 3, '0'), 'PRODUCTS', CASE WHEN g % 3 = 0 THEN 'recherche ' || g ELSE NULL END, '2026-10-18 10:00:00'"
            . ' FROM generate_series(1, ?) g',
            [$userId, Retailer::LECLERC->value, $commons, $choices],
        );
        $connection->executeStatement(
            "INSERT INTO drive_choice_product (choice_id, product_id, verdict, rank, pack_size_value, pack_size_unit_slug)"
            . " SELECT c.id, gen_random_uuid()::text, CASE WHEN v.n < 3 THEN 'ACCEPTED' ELSE 'REJECTED' END, CASE WHEN v.n < 3 THEN v.n END, NULL, NULL"
            . ' FROM drive_choice c, generate_series(1, 3) v(n) WHERE c.user_id = ? AND NOT EXISTS (SELECT 1 FROM drive_choice_product p WHERE p.choice_id = c.id)',
            [$userId],
        );

        return ['commons' => $commons, 'lines' => $recipes * $rowsPerRecipe + $lists * $rowsPerList * 2 + $choices];
    }

    private function createCatalog(int $count): void
    {
        $named = ['Tomate', 'Beurre', 'Farine', 'Couche (taille 4)', 'Lait', 'Sucre', 'Oeuf', 'Sel'];
        $names = array_slice($named, 0, min($count, count($named)));
        for ($i = count($names); $i < $count; ++$i) {
            $names[] = sprintf('Ingredient %03d', $i);
        }

        foreach ($names as $index => $name) {
            $slug = \Repas\Shared\Domain\Tool\StringTool::slugify($name);
            $this->insert('ingredient', [
                'slug' => $slug,
                'name' => $name,
                'image' => "images/$slug.png",
                'slugged_name' => $slug,
                'department' => $index % 2 === 0 ? 'rayon-a' : 'rayon-b',
                'default_cooking_unit' => $index % 3 === 0 ? 'gramme' : 'unite',
                'default_purchase_unit' => $index % 3 === 0 ? 'kilo' : 'unite',
                'creator' => null,
                'compatible_units' => json_encode($index % 3 === 0 ? ['gramme', 'kilo'] : ['unite']),
                'adopted_from' => null,
            ]);
            $this->commons[] = $slug;
            $this->commonNames[$slug] = $name;
        }
        sort($this->commons);

        // Les conversions COMMUNES (aucun ingrédient) : partagées, jamais copiées
        $this->insert('conversion', ['id' => UuidGenerator::new(), 'start_unit' => 'kilo', 'end_unit' => 'gramme', 'coefficient' => 1000.0, 'ingredient' => null]);
        // Les conversions PROPRES : le beurre en a deux, la farine une
        foreach ([['beurre', 'unite', 'gramme', 125.0], ['beurre', 'cuillere-a-soupe', 'gramme', 15.5], ['farine', 'tasse', 'gramme', 140.0]] as [$ingredient, $start, $end, $coefficient]) {
            if (isset($this->commonNames[$ingredient])) {
                $this->insert('conversion', ['id' => UuidGenerator::new(), 'start_unit' => $start, 'end_unit' => $end, 'coefficient' => $coefficient, 'ingredient' => $ingredient]);
            }
        }
    }

    private function createUsers(): void
    {
        // Des identifiants fixes : les comptes sont traités par ordre d'identifiant, un test d'échec en dépend
        $this->bigId = $this->user(new UserBuilder()->withId('10000000-0000-4000-8000-000000000001')->withRecipeAndIngredientStats(20, 6));
        $this->copierId = $this->user(new UserBuilder()->withId('20000000-0000-4000-8000-000000000002'));
        $this->singleId = $this->user(new UserBuilder()->withId('30000000-0000-4000-8000-000000000003'));
        $this->emptyId = $this->user(new UserBuilder()->withId('40000000-0000-4000-8000-000000000004'));
        $this->adminId = $this->user(new UserBuilder()->withId('50000000-0000-4000-8000-000000000005')->isAdmin());
        $this->disabledId = $this->user(new UserBuilder()->withId('60000000-0000-4000-8000-000000000006')->isDisabled());
    }

    private function user(UserBuilder $builder): string
    {
        $user = $builder->build();
        $this->persister->saveUser($user);

        return $user->getId();
    }

    private function createAdmin(): void
    {
        $this->recipe($this->adminId, 'Recette admin A', [$this->commons[0], $this->commons[1]]);
        $this->recipe($this->adminId, 'Recette admin B', [$this->commons[2]]);
        $list = $this->shoppingList($this->adminId, 'ACTIVE', [$this->commons[0], $this->commons[1]], [$this->commons[2]]);
        $this->choice($this->adminId, $this->commons[0], '100001', Retailer::LECLERC->value);
        $this->choice($this->adminId, $this->commons[1], '100001', Retailer::LECLERC->value);
        unset($list);
    }

    private function createBig(): void
    {
        $user = $this->bigId;
        // Ingrédients personnels : « Tomate » prend le slug naturel du commun du même nom (collision), un autre n'a pas de commun
        $tomato = $this->personalIngredient($user, 'Tomate', 'rayon-a');
        $praline = $this->personalIngredient($user, 'Praline', 'rayon-b');
        $this->personalIngredient($user, 'Truc de grand-mere', 'rayon-b');
        $this->personalIngredient($user, 'Epice maison', 'rayon-a');
        $this->personalIngredient($user, 'Sauce secrete', 'rayon-b');
        $this->personalIngredient($user, 'Pate a tartiner', 'rayon-b');
        // Une conversion propre à l'ingrédient personnel : elle reste attachée à lui
        $this->insert('conversion', ['id' => UuidGenerator::new(), 'start_unit' => 'unite', 'end_unit' => 'gramme', 'coefficient' => 30.0, 'ingredient' => $praline]);

        // Vingt recettes : des lignes sur des communs, quelques-unes sur les ingrédients personnels
        for ($i = 0; $i < 20; ++$i) {
            $rows = [$this->commons[$i % count($this->commons)], $this->commons[($i + 3) % count($this->commons)], $this->commons[($i + 5) % count($this->commons)]];
            if ($i % 4 === 0) {
                $rows[] = $tomato;
            }
            if ($i % 5 === 0) {
                $rows[] = $praline;
            }
            $this->recipe($user, sprintf('Recette %02d', $i), $rows);
        }

        // Une liste par statut, dont une ENVOYÉE au drive : lignes issues des recettes et ingrédients ajoutés à la main
        foreach (['ACTIVE', 'PAUSED', 'SENT', 'COMPLETED'] as $status) {
            $this->bigLists[$status] = $this->shoppingList(
                $user,
                $status,
                [$this->commons[0], $this->commons[1], $this->commons[4 % count($this->commons)], $tomato],
                [$this->commons[2], $praline],
            );
        }

        // Les choix du drive : deux magasins d'une enseigne et une autre enseigne, avec produits, rangs, contenances, recherches
        $leclerc = Retailer::LECLERC->value;
        foreach ([$this->commons[0], $this->commons[1], $this->commons[2], $this->commons[3], $this->commons[4 % count($this->commons)]] as $index => $slug) {
            $this->choice($user, $slug, sprintf('2000%02d', $index), $leclerc, '123456', $index % 2 === 0 ? 'recherche memorisee' : null, $index === 1);
            $this->choice($user, $slug, sprintf('3000%02d', $index), $leclerc, '654321');
        }
        $this->choice($user, $this->commons[0], '400001', self::SECOND_RETAILER, '777777');
        // Un choix sur un ingrédient personnel : jamais repointé
        $this->choice($user, $praline, '500001', $leclerc);
    }

    private function createCopier(): void
    {
        $original = UuidGenerator::new();
        for ($i = 0; $i < 3; ++$i) {
            $this->recipe($this->copierId, sprintf('Copie %d', $i), [$this->commons[$i], $this->commons[$i + 1]], $original);
        }
        $this->shoppingList($this->copierId, 'ACTIVE', [$this->commons[0], $this->commons[1]], []);
    }

    private function createSingle(): void
    {
        $slug = $this->personalIngredient($this->singleId, 'Mon unique ingredient', 'rayon-a');
        $this->shoppingList($this->singleId, 'ACTIVE', [$this->commons[0]], [$slug]);
        $this->choice($this->singleId, $this->commons[0], '600001', Retailer::LECLERC->value);
    }

    private function createEmpty(): void
    {
        $this->shoppingList($this->emptyId, 'ACTIVE', [$this->commons[0], $this->commons[1]], [$this->commons[2]]);
        $this->choice($this->emptyId, $this->commons[0], '700001', Retailer::LECLERC->value);
    }

    private function createDisabled(): void
    {
        $this->recipe($this->disabledId, 'Recette d\'un compte desactive', [$this->commons[1], $this->commons[2]]);
    }

    public function personalIngredient(string $userId, string $name, string $department): string
    {
        $slug = \Repas\Shared\Domain\Tool\StringTool::slugify($name . $userId);
        $this->insert('ingredient', [
            'slug' => $slug,
            'name' => $name,
            'image' => '',
            'slugged_name' => \Repas\Shared\Domain\Tool\StringTool::slugify($name),
            'department' => $department,
            'default_cooking_unit' => 'unite',
            'default_purchase_unit' => 'unite',
            'creator' => $userId,
            'compatible_units' => json_encode(['unite']),
            'adopted_from' => null,
        ]);

        return $slug;
    }

    /**
     * @param list<string> $ingredients
     */
    public function recipe(string $authorId, string $name, array $ingredients, ?string $original = null): string
    {
        $id = UuidGenerator::new();
        $this->insert('recipe', ['id' => $id, 'name' => $name, 'slug' => \Repas\Shared\Domain\Tool\StringTool::slugify($name . $id), 'serving' => 4, 'author' => $authorId, 'type' => 'plat', 'orignal' => $original, 'version' => 3]);
        foreach (array_values($ingredients) as $position => $ingredient) {
            $this->insert('recipe_row', ['id' => UuidGenerator::new(), 'ingredient' => $ingredient, 'quantity' => 1.5 + $position / 3, 'unit' => $position % 2 === 0 ? 'gramme' : 'unite', 'recipe' => $id, 'position' => $position]);
        }

        return $id;
    }

    /**
     * @param list<string> $rowIngredients lignes calculées à partir des recettes
     * @param list<string> $addedIngredients ingrédients ajoutés à la main
     */
    public function shoppingList(string $ownerId, string $status, array $rowIngredients, array $addedIngredients): string
    {
        $id = UuidGenerator::new();
        $this->insert('shopping_list', [
            'id' => $id,
            'owner' => $ownerId,
            'created_at' => '2026-10-01 10:00:00',
            'status' => $status,
            'name' => 'Liste ' . strtolower($status),
            'sent_at' => $status === 'SENT' ? '2026-10-10 12:00:00' : null,
            'version' => 5,
        ]);
        foreach (array_values($rowIngredients) as $position => $ingredient) {
            $this->insert('shopping_list_row', ['id' => UuidGenerator::new(), 'shopping_list' => $id, 'ingredient' => $ingredient, 'quantity' => 2.25 + $position, 'unit' => 'gramme', 'checked' => $position % 2 === 0 ? 'true' : 'false']);
        }
        foreach (array_values($addedIngredients) as $position => $ingredient) {
            $this->insert('shopping_list_ingredient', ['id' => UuidGenerator::new(), 'shopping_list' => $id, 'ingredient' => $ingredient, 'unit' => 'unite', 'quantity' => 1.0 + $position]);
        }

        return $id;
    }

    public function choice(string $userId, string $ingredientSlug, string $productBase, string $retailer, string $storeId = '123456', ?string $searchQuery = null, bool $withPackSize = false): string
    {
        $id = UuidGenerator::new();
        $this->insert('drive_choice', [
            'id' => $id,
            'user_id' => $userId,
            'retailer' => $retailer,
            'store_id' => $storeId,
            'ingredient_slug' => $ingredientSlug,
            'kind' => 'PRODUCTS',
            'search_query' => $searchQuery,
            'updated_at' => '2026-10-18 10:00:00',
        ]);
        $this->insert('drive_choice_product', ['choice_id' => $id, 'product_id' => $retailer . '-' . $storeId . '-' . $productBase . 'a', 'verdict' => 'ACCEPTED', 'rank' => 1, 'pack_size_value' => $withPackSize ? '20.000' : null, 'pack_size_unit_slug' => $withPackSize ? 'unite' : null]);
        $this->insert('drive_choice_product', ['choice_id' => $id, 'product_id' => $retailer . '-' . $storeId . '-' . $productBase . 'b', 'verdict' => 'ACCEPTED', 'rank' => 2, 'pack_size_value' => null, 'pack_size_unit_slug' => null]);
        $this->insert('drive_choice_product', ['choice_id' => $id, 'product_id' => $retailer . '-' . $storeId . '-' . $productBase . 'c', 'verdict' => 'REJECTED', 'rank' => null, 'pack_size_value' => null, 'pack_size_unit_slug' => null]);

        return $id;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(string $table, array $row): void
    {
        $this->connection->insert($this->connection->quoteSingleIdentifier($table), array_combine(
            array_map(fn(string $column): string => $this->connection->quoteSingleIdentifier($column), array_keys($row)),
            // Une ligne lue par `SELECT *` rend les booléens (`is_base`) en booléens PHP, que DBAL enverrait comme une chaîne vide quand il est faux
            array_map(static fn(mixed $value): mixed => is_bool($value) ? (int) $value : $value, array_values($row)),
        ));
    }

    /**
     * Une somme de contrôle de TOUTES les lignes des tables que la migration pourrait toucher, pour prouver qu'un mode à blanc ne
     * change rien : une valeur par table.
     *
     * @return array<string, string>
     */
    public function tableHashes(): array
    {
        $hashes = [];
        foreach (['ingredient', 'conversion', 'ingredient_alias', 'recipe', 'recipe_row', 'shopping_list', 'shopping_list_row', 'shopping_list_ingredient', 'drive_choice', 'drive_choice_product', '"user"'] as $table) {
            $hashes[trim($table, '"')] = (string) $this->connection->fetchOne(sprintf("SELECT md5(coalesce(string_agg(t::text, ',' ORDER BY t::text COLLATE \"C\"), '')) FROM %s t", $table));
        }

        return $hashes;
    }

    /**
     * Tout ce qui appartient à un utilisateur, avec les versions, sous la forme d'une seule somme de contrôle : un compte « intact »
     * garde exactement la même valeur.
     */
    public function hashOfUser(string $userId): string
    {
        $parts = [];
        $queries = [
            'SELECT t::text FROM recipe t WHERE t.author = :u',
            'SELECT t::text FROM recipe_row t WHERE t.recipe IN (SELECT id FROM recipe WHERE author = :u)',
            'SELECT t::text FROM shopping_list t WHERE t.owner = :u',
            'SELECT t::text FROM shopping_list_row t WHERE t.shopping_list IN (SELECT id FROM shopping_list WHERE owner = :u)',
            'SELECT t::text FROM shopping_list_ingredient t WHERE t.shopping_list IN (SELECT id FROM shopping_list WHERE owner = :u)',
            'SELECT t::text FROM drive_choice t WHERE t.user_id = :u',
            'SELECT t::text FROM drive_choice_product t WHERE t.choice_id IN (SELECT id FROM drive_choice WHERE user_id = :u)',
            'SELECT t::text FROM ingredient t WHERE t.creator = :u',
            'SELECT t::text FROM ingredient_alias t WHERE t.user_id = :u',
            'SELECT t::text FROM conversion t WHERE t.ingredient IN (SELECT slug FROM ingredient WHERE creator = :u)',
            'SELECT t::text FROM "user" t WHERE t.id = :u',
        ];
        foreach ($queries as $query) {
            $rows = $this->connection->fetchFirstColumn($query, ['u' => $userId]);
            sort($rows);
            $parts[] = implode("\n", $rows);
        }

        return md5(implode("\n--\n", $parts));
    }
}
