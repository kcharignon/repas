<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Model\AdoptionSnapshot;
use Repas\Tests\Helper\AdoptionDataset;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Le garde-fou de la migration d'adoption (ING-003, `docs/ingredients/migration.md` § 4) : AUCUNE colonne de la base qui peut désigner un
 * ingrédient n'échappe au repointage. Le test lit le schéma RÉEL (`information_schema`, donc aussi les tables sans entité) et exige que
 * toute colonne dont le nom contient `ingredient` ou `slug`, et toute colonne JSON, soit déclarée ci-dessous dans l'une des trois
 * catégories, avec sa raison. Une nouvelle table ou colonne qui référence un ingrédient fait échouer ce test : on la déclare ici, ce qui
 * oblige à décider si la migration (et la fusion, ING-005) doit la repointer.
 *
 * Un second garde-fou lit les VALEURS : sur un jeu de données qui remplit toutes les tables utiles, les seules colonnes qui contiennent le
 * slug d'un commun avant la migration sont celles qui sont déclarées (une colonne au nom trompeur, comme `ingredient.adopted_from`, qui
 * ne contient ni « ingredient » ni « slug » dans un nom évident, serait trahie par sa valeur), et la migration n'en fait apparaître aucune autre.
 *
 * Les colonnes REPOINTÉES le sont par `IngredientAdoptionBulkPostgreSQLRepository` (recettes, listes) et par le port
 * `DriveIngredientReferences` (choix du drive) ; `AdoptCommonIngredientsCommandTest` prouve qu'il n'en reste aucune ligne sur un commun.
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
        'drive_choice.ingredient_slug' => 'DriveChoiceFingerprint::ingredientSlugByChoice',
    ];

    /**
     * Des colonnes qui désignent un ingrédient mais que la migration ne repointe pas, et pourquoi.
     */
    private const array DESIGNATES_AN_INGREDIENT_BUT_IS_NOT_REPOINTED = [
        'conversion.ingredient' => 'conversions PROPRES : celles du commun sont copiées avec la copie (nouveaux identifiants, `copyConversions`) ; celles de l\'utilisateur restent à lui',
        'ingredient_alias.alias_slug' => 'la table de correspondance elle-même : la clé est le commun',
        'ingredient_alias.ingredient_slug' => 'la table de correspondance elle-même : la valeur est la copie',
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
        'drive_choice_product.pack_size_unit_slug' => 'un slug d\'UNITÉ (la contenance d\'un produit), pas d\'ingrédient',
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

    public function testTheOnlyColumnsThatHoldTheSlugOfACommonAreTheDeclaredOnesBeforeAndAfterTheMigration(): void
    {
        // Given : le jeu de données de la production, avant la migration
        $dataset = AdoptionDataset::create($this->entityManager, new DataPersister(static::getContainer()));

        // Then : avant, les colonnes qui contiennent un slug de commun sont les références repointées, celles des conversions propres, la clé et son nom normalisé
        $this->assertSame(
            ['conversion.ingredient', 'drive_choice.ingredient_slug', 'ingredient.slug', 'ingredient.slugged_name', 'recipe_row.ingredient', 'shopping_list_ingredient.ingredient', 'shopping_list_row.ingredient'],
            $this->columnsHoldingTheSlugOfACommon($dataset),
        );

        // When
        $tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:adopt-commons'));
        $tester->execute(['--apply' => true, '--accept-sent' => true]);

        // Then : après, aucune AUTRE colonne n'en contient : seules s'ajoutent la provenance et la correspondance. (Les colonnes repointées en
        // contiennent encore, ce sont les lignes de l'administrateur et du compte vide, que la migration ne touche pas ; qu'aucune ligne d'un
        // compte migré n'en garde est prouvé par `AdoptCommonIngredientsCommandTest`.)
        $this->assertSame(
            [
                'conversion.ingredient',
                'drive_choice.ingredient_slug',
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
                : $connection->fetchOne(sprintf('SELECT EXISTS (SELECT 1 FROM %s WHERE %s IN (SELECT slug FROM ingredient WHERE creator IS NULL))', $from, $value));
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
