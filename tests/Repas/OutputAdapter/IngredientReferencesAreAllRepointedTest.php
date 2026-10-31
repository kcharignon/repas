<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\ArrayParameterType;
use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsCommand;
use Repas\Repas\Domain\Model\AdoptionSnapshot;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\AdoptionDataset;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\MergeDataset;
use Repas\Tests\Helper\SeedingDataset;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Le garde-fou du repointage (le moteur d'ING-003, devenu celui de l'amorçage, `docs/ingredients/migration.md` § 4) : AUCUNE colonne de la base qui peut désigner un
 * ingrédient n'échappe au repointage. Le test lit le schéma RÉEL (`information_schema`, donc aussi les tables sans entité) et exige que
 * toute colonne dont le nom contient `ingredient` ou `slug`, et toute colonne JSON, soit déclarée ci-dessous dans l'une des trois
 * catégories, avec sa raison. Une nouvelle table ou colonne qui référence un ingrédient fait échouer ce test : on la déclare ici, ce qui
 * oblige à décider si l'amorçage (et la fusion, ING-005) doit la repointer.
 *
 * Un second garde-fou lit les VALEURS : sur un jeu de données qui remplit toutes les tables utiles, les seules colonnes qui contiennent le
 * slug d'un ancien commun avant l'amorçage sont celles qui sont déclarées (une colonne au nom trompeur, comme `ingredient.adopted_from`, qui
 * ne contient ni « ingredient » ni « slug » dans un nom évident, serait trahie par sa valeur), et l'amorçage n'en fait apparaître aucune autre.
 *
 * Les colonnes REPOINTÉES le sont par `IngredientAdoptionBulkPostgreSQLRepository` (recettes, listes) et par le port
 * `IngredientChoiceRepository` (choix du drive, par l'Open Host Service du `Drive`) ; `SeedIngredientsCommandTest` prouve qu'il n'en reste aucune ligne sur un ancien commun.
 *
 * La FUSION de deux ingrédients (ING-005) repointe EXACTEMENT les mêmes colonnes (`IngredientMergePostgreSQLRepository` et le même port), plus la
 * correspondance (`ingredient_alias`) qu'elle déplace elle-même et les conversions propres de l'absorbé qu'elle supprime avec lui : un troisième
 * garde-fou lit les VALEURS après une fusion et exige que le slug de l'absorbé ne reste dans AUCUNE colonne, hors la clé de son alias.
 */
class IngredientReferencesAreAllRepointedTest extends DatabaseTestCase
{
    /**
     * Ce que la migration repointe : colonne => la table de l'empreinte qui la contrôle.
     */
    private const array REPOINTED = [
        'recipe_row.ingredient' => AdoptionSnapshot::RECIPE_ROW,
        'shopping_list_row.ingredient' => AdoptionSnapshot::SHOPPING_LIST_ROW,
        'shopping_list_ingredient.ingredient' => AdoptionSnapshot::SHOPPING_LIST_INGREDIENT,
        'drive_choice.ingredient_slug' => 'DriveChoicesCommandPort::fingerprint (ingredientSlugByChoice)',
        // La mémoire de phrase de la commande vocale (ADR 0029 décision 11) : la fusion la repointe (`SpokenPhraseChoiceRepository::repoint`) ; la migration d'adoption
        // (ING-003) ne l'a jamais connue, aucune ligne n'existe avant elle
        'spoken_phrase_choice.ingredient_slug' => 'SpokenPhraseChoiceRepository::repoint',
    ];

    /**
     * Des colonnes qui désignent un ingrédient mais que la migration ne repointe pas, et pourquoi.
     */
    private const array DESIGNATES_AN_INGREDIENT_BUT_IS_NOT_REPOINTED = [
        'conversion.ingredient' => 'conversions PROPRES : celles du commun sont copiées avec la copie (nouveaux identifiants, `copyConversions`) ; celles de l\'utilisateur restent à lui ; la fusion supprime celles de l\'ingrédient absorbé avec lui, sans les reporter',
        'ingredient_alias.alias_slug' => 'la table de correspondance elle-même : la clé est le commun (ou l\'ingrédient absorbé par une fusion, qui y est écrit)',
        'ingredient_alias.ingredient_slug' => 'la table de correspondance elle-même : la valeur est la copie (la fusion y remplace l\'absorbé par le gardé, `IngredientAliasRepository::mergeInto`)',
        'ingredient_distinct.first_slug' => '« Pas le même » (ING-006) : les slugs des ingrédients PERSONNELS de l\'utilisateur, jamais ceux d\'un commun, que la migration ne déplace pas ; la fusion et la suppression d\'un ingrédient effacent les paires qui le nomment (`IngredientDistinctRepository::deleteByIngredientSlug`)',
        'ingredient_distinct.second_slug' => 'idem (l\'autre côté de la paire)',
    ];

    /**
     * Des colonnes dont le nom contient `ingredient` ou `slug` sans désigner un ingrédient.
     */
    private const array NOT_AN_INGREDIENT_REFERENCE = [
        'ingredient.slug' => 'la clé de l\'ingrédient lui-même',
        'ingredient.slugged_name' => 'le nom normalisé de l\'ingrédient lui-même',
        'department.slug' => 'un rayon',
        'unit.slug' => 'une unité',
        'recipe.slug' => 'une recette',
        'recipe_type.slug' => 'un type de recette',
        'drive_department_rayon.department_slug' => 'un rayon de Repas, appris par rayon et non par ingrédient',
        'drive_rayon_vote.department_slug' => 'un rayon de Repas, voté par rayon et non par ingrédient',
        'drive_choice_product.pack_size_unit_slug' => 'un slug d\'UNITÉ (la contenance d\'un produit), pas d\'ingrédient',
        'department_list_type.department_slug' => 'un rayon de Repas, rattaché à un type de liste de courses par rayon et non par ingrédient (ADR 0036)',
        'shopping_list_type.slugged_name' => 'le nom normalisé d\'un type de liste de courses (ADR 0036)',
    ];

    /**
     * Les colonnes JSON : aucune ne porte de slug d'ingrédient.
     */
    private const array JSON_WITHOUT_INGREDIENT_SLUGS = [
        'ingredient.compatible_units' => 'des slugs d\'UNITÉS',
        'user.roles' => 'les rôles',
        'user.statistics' => 'des compteurs et des dates',
    ];

    public function testEveryColumnThatCanDesignateAnIngredientIsDeclaredAndTheRepointedOnesAreControlled(): void
    {
        // Given : toutes les colonnes de la base dont le nom évoque un ingrédient ou un slug, et toutes les colonnes JSON
        $columns = array_map(
            static fn(array $row): string => $row[0] . '.' . $row[1],
            $this->entityManager->getConnection()->fetchAllNumeric(
                "SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = 'public'"
                . " AND (column_name ILIKE '%ingredient%' OR column_name ILIKE '%slug%' OR data_type IN ('json', 'jsonb')) ORDER BY table_name, column_name",
            ),
        );

        $declared = [
            ...array_keys(self::REPOINTED),
            ...array_keys(self::DESIGNATES_AN_INGREDIENT_BUT_IS_NOT_REPOINTED),
            ...array_keys(self::NOT_AN_INGREDIENT_REFERENCE),
            ...array_keys(self::JSON_WITHOUT_INGREDIENT_SLUGS),
        ];

        // Then : rien n'est inconnu (une colonne nouvelle oblige à décider), rien n'est périmé
        $this->assertSame(
            [],
            array_values(array_diff($columns, $declared)),
            "Une colonne nouvelle peut désigner un ingrédient : décide dans ce test si la migration d'adoption (ING-003) doit la repointer.",
        );
        $this->assertSame([], array_values(array_diff($declared, $columns)), 'Une déclaration de ce test ne correspond plus à aucune colonne.');
        $this->assertSame(count($declared), count(array_unique($declared)), 'Une colonne est déclarée deux fois.');
    }

    public function testTheOnlyColumnsThatHoldTheSlugOfAnOldCommonAreTheDeclaredOnesBeforeAndAfterTheSeeding(): void
    {
        // Given : le jeu de données de l'amorçage (celui de la production d'avant la passation), avant l'amorçage
        $dataset = SeedingDataset::create($this->entityManager, new DataPersister(static::getContainer()))->adoption;

        // Then : avant, les colonnes qui contiennent un slug d'ancien commun sont les références repointées, celles des conversions propres, la clé et son nom normalisé
        $this->assertSame(
            ['conversion.ingredient', 'drive_choice.ingredient_slug', 'ingredient.slug', 'ingredient.slugged_name', 'recipe_row.ingredient', 'shopping_list_ingredient.ingredient', 'shopping_list_row.ingredient'],
            $this->columnsHoldingTheSlugOfACommon($dataset),
        );

        // When
        $tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:seed'));
        $tester->execute(['--apply' => true, '--accept-sent' => true]);

        // Then : après, aucune AUTRE colonne n'en contient : seules s'ajoutent la provenance et la correspondance, et les choix du drive ont tous
        // quitté les anciens communs. (Les autres colonnes repointées en contiennent encore : les lignes des comptes que l'amorçage ne touche pas.)
        $this->assertSame(
            [
                'conversion.ingredient',
                'ingredient.adopted_from',
                'ingredient.slug',
                'ingredient.slugged_name',
                'ingredient_alias.alias_slug',
                'recipe_row.ingredient',
                'shopping_list_ingredient.ingredient',
                'shopping_list_row.ingredient',
            ],
            $this->columnsHoldingTheSlugOfACommon($dataset),
        );
    }

    /**
     * @return list<string> `table.colonne` de toutes les colonnes de texte ou JSON dont au moins une valeur est le slug d'un commun
     */
    private function columnsHoldingTheSlugOfACommon(AdoptionDataset $dataset): array
    {
        $connection = $dataset->connection;
        $pattern = '"(' . implode('|', array_map('preg_quote', $dataset->commons)) . ')"';
        $found = [];
        foreach ($connection->fetchAllNumeric("SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema = 'public' AND data_type IN ('character varying', 'text', 'json', 'jsonb')") as [$table, $column, $type]) {
            $from = $connection->quoteSingleIdentifier($table);
            $value = $connection->quoteSingleIdentifier($column);
            $holds = str_contains($type, 'json')
                ? $connection->fetchOne(sprintf('SELECT EXISTS (SELECT 1 FROM %s WHERE %s::text ~ ?)', $from, $value), [$pattern])
                : $connection->fetchOne(sprintf('SELECT EXISTS (SELECT 1 FROM %s WHERE %s IN (?))', $from, $value), [$dataset->commons], [ArrayParameterType::STRING]);
            if ($holds) {
                $found[] = $table . '.' . $column;
            }
        }
        sort($found);

        return $found;
    }

    public function testAMergeLeavesNoColumnHoldingTheSlugOfTheAbsorbedIngredientExceptTheKeyOfItsAlias(): void
    {
        // Given : l'ingrédient absorbé est utilisé partout où un ingrédient peut l'être, par le propriétaire (recette, liste, ajout à la main, choix
        // du drive) ; il a une conversion propre et un alias le désigne
        $merge = new MergeDataset($this->entityManager, new DataPersister(static::getContainer()));
        $merge->units();
        $user = $merge->user();
        $kept = $merge->ingredient('Tomate', $user);
        $absorbed = $merge->ingredient('Tomates', $user, adoptedFrom: 'tomates');
        $merge->conversion($kept);
        $merge->conversion($absorbed);
        $merge->recipe('r1', $user->getId(), [[$absorbed->getSlug(), 1.0, 'gramme']]);
        $merge->shoppingList('l1', $user->getId(), rows: [['x1', $absorbed->getSlug(), 1.0, 'kilo']], items: [['y1', $absorbed->getSlug(), 1.0, 'kilo']]);
        $merge->choice('c1', $user->getId(), $absorbed->getSlug());
        $this->entityManager->getConnection()->insert('spoken_phrase_choice', ['user_id' => $user->getId(), 'phrase' => 'tomates', 'ingredient_slug' => $absorbed->getSlug(), 'times' => 2, 'updated_at' => '2026-10-20 03:00:00']);
        $this->entityManager->getConnection()->insert('ingredient_alias', ['user_id' => $user->getId(), 'alias_slug' => 'tomates', 'ingredient_slug' => $absorbed->getSlug(), 'kind' => 'adoption', 'source' => 'migration-essai', 'created_at' => '2026-10-20 03:00:00']);
        $holding = fn(): array => $this->columnsHolding($merge->connection, $absorbed->getSlug());

        // Then : avant, toutes les colonnes qui peuvent désigner un ingrédient le contiennent
        $this->assertSame(
            ['conversion.ingredient', 'drive_choice.ingredient_slug', 'ingredient.slug', 'ingredient_alias.ingredient_slug', 'recipe_row.ingredient', 'shopping_list_ingredient.ingredient', 'shopping_list_row.ingredient', 'spoken_phrase_choice.ingredient_slug'],
            $holding(),
        );

        // When
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new MergeIngredientsCommand($user->getId(), $absorbed->getSlug(), $kept->getSlug()));

        // Then : après, seule la clé de l'alias de la fusion le contient ; chaque colonne repointée désigne le gardé
        $this->assertSame(['ingredient_alias.alias_slug'], $holding());
        foreach (array_keys(self::REPOINTED) as $column) {
            [$table, $name] = explode('.', $column);
            $this->assertContains($kept->getSlug(), $merge->connection->fetchFirstColumn(sprintf('SELECT %s FROM %s', $name, $table)), $column);
        }
        $this->assertSame([$kept->getSlug(), 'merge'], [
            $merge->connection->fetchOne("SELECT ingredient_slug FROM ingredient_alias WHERE alias_slug = 'tomates'"),
            $merge->connection->fetchOne("SELECT source FROM ingredient_alias WHERE alias_slug = 'tomates'"),
        ]);
    }

    /**
     * @return list<string> `table.colonne` de toutes les colonnes de texte ou JSON dont au moins une valeur est ce slug
     */
    private function columnsHolding(\Doctrine\DBAL\Connection $connection, string $slug): array
    {
        $found = [];
        foreach ($connection->fetchAllNumeric("SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema = 'public' AND data_type IN ('character varying', 'text', 'json', 'jsonb')") as [$table, $column, $type]) {
            $from = $connection->quoteSingleIdentifier($table);
            $value = $connection->quoteSingleIdentifier($column);
            $holds = str_contains($type, 'json')
                ? $connection->fetchOne(sprintf('SELECT EXISTS (SELECT 1 FROM %s WHERE %s::text ~ ?)', $from, $value), ['"' . preg_quote($slug) . '"'])
                : $connection->fetchOne(sprintf('SELECT EXISTS (SELECT 1 FROM %s WHERE %s = ?)', $from, $value), [$slug]);
            if ($holds) {
                $found[] = $table . '.' . $column;
            }
        }
        sort($found);

        return $found;
    }

    public function testEveryRepointedTableIsOneOfTheTablesTheFingerprintControls(): void
    {
        // Les trois tables de Repas repointées sont exactement celles que l'empreinte compte, somme et hache
        $tables = array_values(array_map(static fn(string $column): string => explode('.', $column)[0], array_keys(array_filter(self::REPOINTED, static fn(string $control): bool => !str_contains($control, '::')))));
        sort($tables);
        $fingerprinted = [AdoptionSnapshot::RECIPE_ROW, AdoptionSnapshot::SHOPPING_LIST_ROW, AdoptionSnapshot::SHOPPING_LIST_INGREDIENT];
        sort($fingerprinted);

        $this->assertSame($fingerprinted, $tables);
    }
}
