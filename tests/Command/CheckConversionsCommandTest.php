<?php

namespace Repas\Tests\Command;


use Doctrine\DBAL\Connection;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\ReferenceUniversalConversions;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `repas:conversions:check` de bout en bout : la commande de console, le vrai bus, la vraie base. Un rapport en lecture seule, dont le code de sortie
 * est 0 que le verdict soit VERT ou À REGARDER, et dont la sortie n'a jamais d'adresse e-mail, d'identifiant ni de nom d'ingrédient ou de recette.
 */
class CheckConversionsCommandTest extends DatabaseTestCase
{
    private const string CENTILITRE_ID = '8f31ad66-feaf-4c35-a17b-c755d8c1c889';

    private CommandTester $tester;
    private DataPersister $persister;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tester = new CommandTester(new Application(self::$kernel)->find('repas:conversions:check'));
        $this->persister = new DataPersister(static::getContainer());
        $this->connection = $this->entityManager->getConnection();
    }

    public function testAHealthyBaseIsGreen(): void
    {
        // Given : les 13 universelles et un compte dont tout se convertit
        $this->insertReferenceLines();
        $user = $this->savedUser();
        $flour = $this->savedFlour($user);
        $this->savedRecipe($user, $flour, 'zzz recette secrète', 'gramme');

        // When
        $exit = $this->tester->execute([]);
        $output = $this->tester->getDisplay();

        // Then
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Conversions : santé (lecture seule)', $output);
        $this->assertStringContainsString('Conversions universelles de référence : 13 présente(s) sur 13.', $output);
        $this->assertStringContainsString('Conversions universelles en plus de la référence : aucune.', $output);
        $this->assertStringContainsString('Conversions propres qui répètent une paire universelle : aucune.', $output);
        $this->assertStringContainsString('Comptes : 1.', $output);
        $this->assertStringContainsString(sprintf('Compte n°1 [%s] (actif) : 1 ingrédient(s) dont 0 non convertible(s) ; 1 recette(s), 1 ligne(s) dont 0 non convertible(s)', substr(hash('sha256', $user->getId()), 0, 8)), $output);
        $this->assertStringContainsString("Totaux : 0 ingrédient(s) dont l'unité des recettes ne se convertit pas en l'unité d'achat, 0 ligne(s) de recettes qui ne se convertissent pas.", $output);
        $this->assertStringContainsString('verdict : VERT', $output);
        $this->assertStringContainsString("Rapport seulement : rien n'a été modifié.", $output);
    }

    public function testAMissingLineADivertedOneAndANonConvertingIngredientAreToLookAtWithTheSameExitCode(): void
    {
        // Given : « centilitre > millilitre » manque et son identifiant est pris par une conversion détournée (propre à un ingrédient), « kilo > gramme »
        // dit autre chose, une universelle est en plus ; la farine du compte a une unité d'achat (la boîte) que rien ne relie au gramme des recettes
        $this->insertReferenceLines(except: ['centilitre > millilitre', 'kilo > gramme']);
        $this->insertConversion('other', 'kilo', 'gramme', 900, null);
        $this->insertConversion('extra', 'flacon', 'bouteille', 1, null);
        $this->insertConversion(self::CENTILITRE_ID, 'tasse-a-cafe', 'gramme', 120, 'farine-quelconque');
        $user = $this->savedUser();
        $flour = new IngredientBuilder()->isFlour()->withDefaultPurchaseUnit(new UnitBuilder()->isBox())->withCreator($user, true)->build();
        $this->persister->saveIngredient($flour);
        $this->savedRecipe($user, $flour, 'zzz recette secrète', 'gramme');

        // When
        $exit = $this->tester->execute([]);
        $output = $this->tester->getDisplay();

        // Then : un rapport, jamais un échec
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Conversions universelles de référence : 12 présente(s) sur 13.', $output);
        $this->assertStringContainsString('manque : centilitre > millilitre x 10', $output);
        $this->assertStringContainsString('autre coefficient : kilo > gramme x 1000 attendu, kilo > gramme x 900 en base', $output);
        $this->assertStringContainsString('Conversions universelles en plus de la référence : flacon > bouteille x 1.', $output);
        $this->assertStringContainsString("Conversions propres qui répètent une paire universelle : 1 dont 1 portent l'identifiant d'une ligne de référence (détournées).", $output);
        $this->assertStringContainsString('tasse-a-cafe > gramme x 120 : 1 ligne(s), dont 1 détournée(s)', $output);
        $this->assertStringContainsString('1 ingrédient(s) dont 1 non convertible(s) ; 1 recette(s), 1 ligne(s) dont 1 non convertible(s)', $output);
        $this->assertStringContainsString("Totaux : 1 ingrédient(s) dont l'unité des recettes ne se convertit pas en l'unité d'achat, 1 ligne(s) de recettes qui ne se convertissent pas.", $output);
        $this->assertStringContainsString('verdict : À REGARDER', $output);
    }

    public function testTheOutputNeverContainsAnEmailAnIdentifierNorAnIngredientOrRecipeName(): void
    {
        // Given : un compte avec une adresse reconnaissable, un ingrédient et une recette au nom reconnaissable, une ligne détournée
        $this->insertReferenceLines(except: ['centilitre > millilitre']);
        $this->insertConversion(self::CENTILITRE_ID, 'tasse-a-cafe', 'gramme', 120, 'farine-de-quelquun');
        $user = new UserBuilder()->withEmail('personne.secrete@example.org')->build();
        $this->persister->saveUser($user);
        $ingredient = new IngredientBuilder()->isFlour()->withName('zzz ingrédient secret')->withCreator($user, true)->build();
        $this->persister->saveIngredient($ingredient);
        $this->savedRecipe($user, $ingredient, 'zzz recette secrète', 'cuillere-a-soupe');

        // When
        $this->tester->execute([]);
        $output = $this->tester->getDisplay();

        // Then : ni l'adresse, ni l'identifiant du compte, ni un identifiant quelconque (UUID), ni les noms, ni le slug de l'ingrédient
        $this->assertStringNotContainsString('personne.secrete', $output);
        $this->assertStringNotContainsString('example.org', $output);
        $this->assertStringNotContainsString($user->getId(), $output);
        $this->assertStringNotContainsString('farine-de-quelquun', $output);
        $this->assertStringNotContainsString('zzz', $output);
        $this->assertStringNotContainsString($ingredient->getSlug(), $output);
        $this->assertStringNotContainsString(self::CENTILITRE_ID, $output);
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $output);
        // Le compte est désigné par son rang et sa référence courte
        $this->assertStringContainsString(sprintf('Compte n°1 [%s]', substr(hash('sha256', $user->getId()), 0, 8)), $output);
    }

    public function testItNeverWrites(): void
    {
        // Given : une base qui a de quoi être à regarder
        $this->insertReferenceLines(except: ['centilitre > millilitre']);
        $user = $this->savedUser();
        $this->savedRecipe($user, $this->savedFlour($user), 'zzz recette', 'cuillere-a-soupe');
        $before = $this->tableHashes();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->tester->execute([]);

        // Then : aucune ligne n'a bougé, et aucune requête d'écriture ni verrou n'a même été envoyé
        $this->assertSame($before, $this->tableHashes());
        $this->assertGreaterThan(0, $counter->count());
        $this->assertSame([], array_values(array_filter(
            $counter->statements(),
            static fn(string $sql): bool => preg_match('/^\\s*(INSERT|UPDATE|DELETE)\\b|\\b(INSERT\\s+INTO|DELETE\\s+FROM|UPDATE\\s+\\w+\\s+SET)\\b|pg_advisory|FOR\\s+UPDATE/i', $sql) === 1,
        )));
    }

    /**
     * @param list<string> $except les paires « début > fin » à ne pas créer
     */
    private function insertReferenceLines(array $except = []): void
    {
        foreach (ReferenceUniversalConversions::LINES as [$id, $start, $end, $coefficient]) {
            if (!in_array("$start > $end", $except, true)) {
                $this->insertConversion($id, $start, $end, $coefficient, null);
            }
        }
    }

    private function insertConversion(string $id, string $start, string $end, float $coefficient, ?string $ingredient): void
    {
        $this->connection->executeStatement(
            'INSERT INTO conversion (id, start_unit, end_unit, coefficient, ingredient) VALUES (?, ?, ?, ?, ?)',
            [$id, $start, $end, $coefficient, $ingredient],
        );
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedFlour(User $user): Ingredient
    {
        $flour = new IngredientBuilder()->isFlour()->withCreator($user, true)->build();
        $this->persister->saveIngredient($flour);

        return $flour;
    }

    private function savedRecipe(User $author, Ingredient $ingredient, string $name, string $unit): void
    {
        $unitBuilder = match ($unit) {
            'gramme' => new UnitBuilder()->isGramme(),
            default => new UnitBuilder()->isSoupSpoon(),
        };
        $this->persister->saveRecipe(
            new RecipeBuilder()->withAuthor($author)->withName($name)->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit($unitBuilder)->withQuantity(1))->build(),
        );
    }

    /**
     * @return array<string, string> une empreinte du contenu de chaque table que la commande lit
     */
    private function tableHashes(): array
    {
        $hashes = [];
        foreach (['conversion', 'ingredient', 'recipe', 'recipe_row', '"user"', 'unit'] as $table) {
            $hashes[$table] = (string) $this->connection->fetchOne("SELECT md5(coalesce(string_agg(t::text, '|' ORDER BY t::text), '')) FROM $table t");
        }

        return $hashes;
    }
}
