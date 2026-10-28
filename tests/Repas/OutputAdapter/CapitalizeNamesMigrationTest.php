<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261028160000;
use Psr\Log\NullLogger;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261028160000.php';

/**
 * La migration de la première lettre des noms en majuscule (ADR 0030), sur la VRAIE base : les noms de recettes et d'ingrédients déjà
 * enregistrés sont corrigés en PHP (accents et ligatures compris), seule la première lettre change, les autres lignes et toutes les autres
 * colonnes (slugs, `slugged_name`, version) ne sont pas touchées, et la rejouer ne change rien.
 */
class CapitalizeNamesMigrationTest extends DatabaseTestCase
{
    /**
     * @var array<string, array{string, string}> le nom enregistré avant la migration, le nom après
     */
    private const array NAMES = [
        'minuscules' => ['pâtes carbonara', 'Pâtes carbonara'],
        'un mot' => ['farine', 'Farine'],
        'la suite est conservée' => ['de la farine T55', 'De la farine T55'],
        'suite en majuscules conservée' => ['lait de COCO', 'Lait de COCO'],
        'accent' => ['éclair au café', 'Éclair au café'],
        'ligature œ' => ['œufs à la coque', 'Œufs à la coque'],
        'ligature œ au milieu' => ['bœuf bourguignon', 'Bœuf bourguignon'],
        'apostrophe typographique après la lettre' => ["l\u{2019}ail", "L\u{2019}ail"],
        'espace de tête conservé' => [' espace', ' Espace'],
        'déjà une majuscule' => ['Pâtes carbonara', 'Pâtes carbonara'],
        'déjà une majuscule accentuée' => ['Éclair', 'Éclair'],
        'déjà la ligature en majuscule' => ['Œufs', 'Œufs'],
        'tout en majuscules' => ['ÉCLAIR', 'ÉCLAIR'],
        'chiffre' => ['00 farine', '00 farine'],
        'chiffre puis lettre' => ['3 fromages', '3 fromages'],
        'parenthèse' => ['(bio) tomate', '(bio) tomate'],
        'apostrophe en tête' => ["'sel", "'sel"],
        'tiret' => ['- sel', '- sel'],
        'vide' => ['', ''],
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->entityManager->getConnection();
    }

    public function testEveryExistingNameOfAnIngredientAndOfARecipeStartsWithACapitalAfterwardsAndTheRestIsKept(): void
    {
        // Given
        $slugs = [];
        foreach (array_values(self::NAMES) as $index => [$before]) {
            $slugs[$index] = 'ingredient-' . $index;
            $this->insertIngredient($slugs[$index], $before);
            $this->insertRecipe('recette-' . $index, $before);
        }

        // When
        $this->runMigration();

        // Then
        foreach (array_values(self::NAMES) as $index => [$before, $after]) {
            $this->assertSame($after, $this->nameOf('ingredient', 'slug', 'ingredient-' . $index), "ingrédient « $before »");
            $this->assertSame($after, $this->nameOf('recipe', 'id', 'recette-' . $index), "recette « $before »");
        }
    }

    public function testOnlyTheNameChangesNeverTheSlugsTheNormalizedNameTheVersionOrAnyOtherColumn(): void
    {
        // Given
        $this->insertIngredient('oeufs', 'œufs', sluggedName: 'oeufs');
        $this->insertRecipe('recette-1', 'éclair', slug: 'eclair', version: 7);
        $ingredient = $this->rowWithoutName('SELECT * FROM ingredient WHERE slug = ?', 'oeufs');
        $recipe = $this->rowWithoutName('SELECT * FROM recipe WHERE id = ?', 'recette-1');

        // When
        $this->runMigration();

        // Then
        $this->assertSame($ingredient, $this->rowWithoutName('SELECT * FROM ingredient WHERE slug = ?', 'oeufs'));
        $this->assertSame($recipe, $this->rowWithoutName('SELECT * FROM recipe WHERE id = ?', 'recette-1'));
        $this->assertSame(['Œufs', 'oeufs'], [$this->nameOf('ingredient', 'slug', 'oeufs'), $this->connection->fetchOne("SELECT slugged_name FROM ingredient WHERE slug = 'oeufs'")]);
        $this->assertSame(['Éclair', 'eclair', 7], [$this->nameOf('recipe', 'id', 'recette-1'), $this->connection->fetchOne("SELECT slug FROM recipe WHERE id = 'recette-1'"), (int) $this->connection->fetchOne("SELECT version FROM recipe WHERE id = 'recette-1'")]);
    }

    public function testItOnlyWritesTheRowsThatChange(): void
    {
        // Given : un nom à corriger, trois noms déjà bons
        $this->insertIngredient('a', 'farine');
        $this->insertIngredient('b', 'Beurre');
        $this->insertIngredient('c', '00 farine');
        $this->insertRecipe('r1', 'Gratin');

        // When
        $statements = $this->migrationStatements();

        // Then : une seule instruction, qui ne vise que la ligne à corriger, avec le nom lu pour garde
        $this->assertCount(1, $statements);
        $this->assertMatchesRegularExpression('/^UPDATE (ingredient|recipe) SET name = \? WHERE (slug|id) = \? AND name = \?$/', $statements[0]->getStatement());
        $this->assertSame(['Farine', 'a', 'farine'], $statements[0]->getParameters());
    }

    public function testReplayingItChangesNothingAndWritesNothing(): void
    {
        // Given
        $this->insertIngredient('a', 'farine');
        $this->insertRecipe('r1', 'œufs au plat');
        $this->runMigration();
        $after = $this->allNames();

        // When
        $statements = $this->migrationStatements();
        $this->runMigration();

        // Then : aucune instruction, mêmes données
        $this->assertSame([], $statements);
        $this->assertSame($after, $this->allNames());
        $this->assertSame(['Farine', 'Œufs au plat'], [$this->nameOf('ingredient', 'slug', 'a'), $this->nameOf('recipe', 'id', 'r1')]);
    }

    public function testAnEmptyBaseHasNothingToDo(): void
    {
        // Then
        $this->assertSame([], $this->migrationStatements());
    }

    public function testManyRowsAreCorrectedAndOnlyTheLowercaseOnesAreWritten(): void
    {
        // Given : 3 000 ingrédients et 1 000 recettes, un sur deux déjà bien écrit
        $this->connection->executeStatement(
            "INSERT INTO ingredient (slug, name, image, department, default_cooking_unit, default_purchase_unit, compatible_units, slugged_name)"
            . " SELECT 'vrac-' || i, CASE WHEN i % 2 = 0 THEN 'étoile ' || i ELSE 'Étoile ' || i END, '', 'divers', 'unite', 'unite', '[]', 'etoile-' || i"
            . ' FROM generate_series(1, 3000) AS i',
        );
        $this->connection->executeStatement(
            "INSERT INTO recipe (id, name, slug, serving, author, type, version)"
            . " SELECT 'vrac-' || i, CASE WHEN i % 2 = 0 THEN 'œuf ' || i ELSE 'Œuf ' || i END, 'oeuf-' || i, 4, 'author', 'plat', 1"
            . ' FROM generate_series(1, 1000) AS i',
        );

        // When
        $statements = $this->migrationStatements();
        $this->runMigration();

        // Then
        $this->assertCount(1500 + 500, $statements);
        $this->assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM ingredient WHERE name LIKE 'étoile %'"));
        $this->assertSame(3000, (int) $this->connection->fetchOne("SELECT count(*) FROM ingredient WHERE name LIKE 'Étoile %'"));
        $this->assertSame(1000, (int) $this->connection->fetchOne("SELECT count(*) FROM recipe WHERE name LIKE 'Œuf %'"));
    }

    public function testDownDoesNothingBecauseTheOldCaseCannotBeRebuilt(): void
    {
        // Given
        $migration = new Version20261028160000($this->connection, new NullLogger());

        // When
        $migration->down(new Schema());

        // Then : l'instruction neutre, rien d'autre
        $this->assertSame(['SELECT 1'], array_map(static fn($query): string => $query->getStatement(), $migration->getSql()));
    }

    public function testTheRuleOfTheMigrationIsTheRuleOfTheApplicationForEveryFirstCharacter(): void
    {
        // Given : chaque point de code en tête, seul, derrière des espaces, ou suivi d'une suite
        $checked = 0;
        foreach (array_merge(range(0, 0xD7FF), range(0xE000, 0x1FFFF)) as $codePoint) {
            $character = mb_chr($codePoint, 'UTF-8');
            if ($character === false) {
                continue;
            }
            foreach ([$character, ' ' . $character . 'x', $character . ' suite eN mIxTe'] as $text) {
                // When / Then
                $this->assertSame(StringTool::capitalizeFirst($text), Version20261028160000::capitalizeFirst($text), sprintf('U+%04X', $codePoint));
            }
            $checked++;
        }
        foreach (['', "\xff\xfe", ' ', "a\nb", "e\u{0301}clair", "\u{FB01}let"] as $text) {
            $this->assertSame(StringTool::capitalizeFirst($text), Version20261028160000::capitalizeFirst($text));
        }
        $this->assertGreaterThan(60000, $checked);
    }

    /**
     * @return list<\Doctrine\Migrations\Query\Query>
     */
    private function migrationStatements(): array
    {
        $migration = new Version20261028160000($this->connection, new NullLogger());
        $migration->up(new Schema());

        return $migration->getSql();
    }

    private function runMigration(): void
    {
        foreach ($this->migrationStatements() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters());
        }
    }

    private function insertIngredient(string $slug, string $name, ?string $sluggedName = null): void
    {
        $this->connection->insert('ingredient', [
            'slug' => $slug,
            'name' => $name,
            'image' => '',
            'department' => 'divers',
            'default_cooking_unit' => 'unite',
            'default_purchase_unit' => 'unite',
            'creator' => 'user-id',
            'compatible_units' => '[]',
            'slugged_name' => $sluggedName ?? StringTool::slugify($name),
        ]);
    }

    private function insertRecipe(string $id, string $name, ?string $slug = null, int $version = 1): void
    {
        $this->connection->insert('recipe', [
            'id' => $id,
            'name' => $name,
            'slug' => $slug ?? StringTool::slugify($name),
            'serving' => 4,
            'author' => 'user-id',
            'type' => 'plat',
            'version' => $version,
        ]);
    }

    private function nameOf(string $table, string $key, string $value): string
    {
        return (string) $this->connection->fetchOne(sprintf('SELECT name FROM %s WHERE %s = ?', $table, $key), [$value]);
    }

    /**
     * @return array<string, mixed> la ligne sans son nom
     */
    private function rowWithoutName(string $sql, string $key): array
    {
        $row = $this->connection->fetchAssociative($sql, [$key]);
        unset($row['name']);

        return $row;
    }

    /**
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function allNames(): array
    {
        return [
            $this->connection->fetchAllAssociative('SELECT slug, name FROM ingredient ORDER BY slug'),
            $this->connection->fetchAllAssociative('SELECT id, name FROM recipe ORDER BY id'),
        ];
    }
}
