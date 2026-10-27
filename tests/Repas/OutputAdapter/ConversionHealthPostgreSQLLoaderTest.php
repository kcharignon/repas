<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Repas\Repas\Application\Loader\ConversionHealthLoader;
use Repas\Repas\Application\Query\GetConversionHealth\ConversionHealthAccountDTO;
use Repas\Repas\Application\Query\GetConversionHealth\ConversionHealthDTO;
use Repas\Repas\Application\Query\GetConversionHealth\ReferenceConversionDTO;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\ReferenceUniversalConversions;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Le rapport de santé des conversions sur la VRAIE base : les universelles de référence présentes, manquantes, à l'envers ou avec un autre
 * coefficient, les conversions propres qui répètent une paire universelle (et celles qu'une ancienne version a détournées), et, par compte, les
 * ingrédients et les lignes de recettes dont l'unité ne se convertit pas en l'unité d'achat — le tout en lecture seule et en nombre constant de requêtes.
 */
class ConversionHealthPostgreSQLLoaderTest extends DatabaseTestCase
{
    private const string CENTILITRE_ID = '8f31ad66-feaf-4c35-a17b-c755d8c1c889';

    private ConversionHealthLoader $loader;
    private DataPersister $persister;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = static::getContainer()->get(ConversionHealthLoader::class);
        $this->persister = new DataPersister(static::getContainer());
        $this->connection = $this->entityManager->getConnection();
    }

    public function testAHealthyBaseIsGreen(): void
    {
        // Given : les 13 universelles, un compte dont la farine (gramme en recette, kilo à l'achat) se convertit par « kilo > gramme »
        $this->insertReferenceLines();
        $user = $this->savedUser('00000000-0000-4000-8000-000000000001');
        $flour = $this->savedFlour($user);
        $this->savedRecipe($user, $flour, 'gramme', 'kilo');

        // When
        $report = $this->loader->report();

        // Then
        $this->assertTrue($report->isHealthy());
        $this->assertSame([13, 0, 0], [$report->presentReference(), $report->missingReference(), $report->otherCoefficientReference()]);
        $this->assertSame([], $report->extraUniversal);
        $this->assertSame([], $report->repeated);
        $this->assertEquals([new ConversionHealthAccountDTO(1, substr(hash('sha256', $user->getId()), 0, 8), 'ACTIVE', 1, 0, 1, 2, 0)], $report->accounts);
        $this->assertSame([0, 0], [$report->ingredientsNotConvertible(), $report->recipeRowsNotConvertible()]);
    }

    public function testEachReferenceLineIsPresentMissingReversedOrWithAnotherCoefficientAndTheExtraOnesAreListed(): void
    {
        // Given : « centilitre > millilitre » à l'envers (même chose dite autrement), « kilo > gramme » avec un autre coefficient, « pot > boite » absente,
        // et deux universelles hors référence
        $this->insertReferenceLines(except: ['centilitre > millilitre', 'kilo > gramme', 'pot > boite']);
        $this->insertConversion('rev', 'millilitre', 'centilitre', 0.1, null);
        $this->insertConversion('other', 'kilo', 'gramme', 900, null);
        $this->insertConversion('extra-1', 'flacon', 'bouteille', 1, null);
        $this->insertConversion('extra-2', 'bocal', 'pot', 2, null);

        // When
        $report = $this->loader->report();

        // Then
        $byLabel = [];
        foreach ($report->reference as $line) {
            $byLabel[$line->label] = [$line->state, $line->found];
        }
        $this->assertCount(13, $report->reference);
        $this->assertSame([ReferenceConversionDTO::PRESENT, 'millilitre > centilitre x 0.1'], $byLabel['centilitre > millilitre x 10']);
        $this->assertSame([ReferenceConversionDTO::OTHER_COEFFICIENT, 'kilo > gramme x 900'], $byLabel['kilo > gramme x 1000']);
        $this->assertSame([ReferenceConversionDTO::MISSING, null], $byLabel['pot > boite x 1']);
        $this->assertSame([ReferenceConversionDTO::PRESENT, 'litre > millilitre x 1000'], $byLabel['litre > millilitre x 1000']);
        $this->assertSame([12, 1, 1], [$report->presentReference(), $report->missingReference(), $report->otherCoefficientReference()]);
        $this->assertSame(['bocal > pot x 2', 'flacon > bouteille x 1'], $report->extraUniversal);
        $this->assertFalse($report->isHealthy());
    }

    public function testOwnConversionsThatRepeatAUniversalPairAreCountedAndTheHijackedOnesToo(): void
    {
        // Given : « kilo > gramme » universelle ; deux conversions propres de la même paire, une à l'envers, une conversion qui ne répète rien, et
        // une ligne détournée : elle porte l'identifiant de « centilitre > millilitre » (dont la ligne universelle manque) mais a pris la paire de la farine
        $this->insertReferenceLines(except: ['centilitre > millilitre']);
        $this->insertConversion('own-1', 'kilo', 'gramme', 1000, 'farine-1');
        $this->insertConversion('own-2', 'kilo', 'gramme', 1000, 'farine-2');
        $this->insertConversion('own-3', 'gramme', 'kilo', 0.001, 'farine-3');
        $this->insertConversion('own-4', 'cuillere-a-soupe', 'gramme', 10, 'farine-1');
        $this->insertConversion(self::CENTILITRE_ID, 'tasse-a-cafe', 'gramme', 120, 'farine-2');

        // When
        $report = $this->loader->report();

        // Then : par paire (telle qu'écrite) et coefficient, ni ingrédient ni identifiant
        $this->assertEquals(
            [
                ['gramme > kilo x 0.001', 1, 0],
                ['kilo > gramme x 1000', 2, 0],
                ['tasse-a-cafe > gramme x 120', 1, 1],
            ],
            array_map(static fn($line): array => [$line->label, $line->count, $line->hijacked], $report->repeated),
        );
        $this->assertSame([4, 1], [$report->repeatedCount(), $report->hijackedCount()]);
    }

    public function testAnOwnLineOnAMissingReferencePairIsReportedAsRepeatingIt(): void
    {
        // Given : la ligne universelle « centilitre > millilitre » manque, un ingrédient a la sienne pour la même paire
        $this->insertReferenceLines(except: ['centilitre > millilitre']);
        $this->insertConversion('own-1', 'centilitre', 'millilitre', 10, 'creme');

        // When
        $report = $this->loader->report();

        // Then : la paire est universelle de référence, même sans sa ligne
        $this->assertSame([['centilitre > millilitre x 10', 1, 0]], array_map(static fn($line): array => [$line->label, $line->count, $line->hijacked], $report->repeated));
    }

    public function testIngredientsAndRecipeRowsThatDoNotConvertAreCountedPerAccountInIdentifierOrder(): void
    {
        // Given : aucune « kilo > gramme » universelle. Le premier compte a une farine sans conversion (gramme en recette, kilo à l'achat) et deux
        // recettes, une ligne en gramme (non convertible) et une en kilo (l'unité d'achat) ; le second a la sienne, avec sa conversion propre, et une
        // ligne en cuillère à soupe (non convertible) à côté d'une ligne en gramme (convertible)
        $this->insertConversion('x', 'litre', 'millilitre', 1000, null);
        $second = $this->savedUser('00000000-0000-4000-8000-000000000002', 'ACTIVE');
        $first = $this->savedUser('00000000-0000-4000-8000-000000000001', 'PENDING');
        $firstFlour = $this->savedFlour($first);
        $this->savedRecipe($first, $firstFlour, 'gramme', 'kilo');
        $this->savedRecipe($first, $firstFlour, 'kilo', 'kilo');
        $secondFlour = $this->savedFlour($second);
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->withIngredient($secondFlour)->build());
        $this->savedRecipe($second, $secondFlour, 'cuillere-a-soupe', 'gramme');

        // When
        $report = $this->loader->report();

        // Then : les comptes par ordre d'identifiant, rang à partir de 1, référence courte, statut
        $this->assertEquals(
            [
                new ConversionHealthAccountDTO(1, substr(hash('sha256', $first->getId()), 0, 8), 'PENDING', 1, 1, 2, 4, 1),
                new ConversionHealthAccountDTO(2, substr(hash('sha256', $second->getId()), 0, 8), 'ACTIVE', 1, 0, 1, 2, 1),
            ],
            $report->accounts,
        );
        $this->assertSame([1, 2], [$report->ingredientsNotConvertible(), $report->recipeRowsNotConvertible()]);
        $this->assertFalse($report->isHealthy(), 'un ingrédient ne convertit pas son unité des recettes en son unité d\'achat');
    }

    public function testAnIngredientAndRowsOfNoExistingAccountAreCountedApart(): void
    {
        // Given : un ingrédient dont le créateur n'est aucun compte (aucune clé étrangère), et une recette d'un auteur qui n'existe pas
        $ghost = new UserBuilder()->withId('00000000-0000-4000-8000-0000000000ff')->build();
        $flour = new IngredientBuilder()->isFlour()->withCreator($ghost, true)->build();
        $this->persister->saveIngredient($flour);
        $recipe = new RecipeBuilder()->withAuthor($ghost)->addRow(new RecipeRowBuilder()->withIngredient($flour)->withUnit(new UnitBuilder()->isGramme())->withQuantity(1))->build();
        $this->persister->saveRecipe($recipe);
        $this->connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$ghost->getId()]);

        // When
        $report = $this->loader->report();

        // Then
        $this->assertSame([], $report->accounts);
        $this->assertSame([1, 1], [$report->unattachedIngredientsNotConvertible, $report->unattachedRecipeRowsNotConvertible]);
        $this->assertFalse($report->isHealthy());
    }

    public function testItReadsInAConstantNumberOfQueriesAndWritesNothing(): void
    {
        // Given : un compte, puis deux de plus avec leurs ingrédients, leurs conversions et leurs recettes
        $this->insertReferenceLines();
        $first = $this->savedUser('00000000-0000-4000-8000-000000000001');
        $this->savedRecipe($first, $this->savedFlour($first), 'gramme', 'kilo');
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();
        $this->loader->report();
        $few = $counter->count();

        foreach (['00000000-0000-4000-8000-000000000002', '00000000-0000-4000-8000-000000000003'] as $id) {
            $user = $this->savedUser($id);
            $flour = $this->savedFlour($user);
            $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->withIngredient($flour)->build());
            $this->savedRecipe($user, $flour, 'cuillere-a-soupe', 'gramme');
        }
        $counter->reset();

        // When
        $report = $this->loader->report();
        $many = $counter->count();

        // Then : cinq lectures dans les deux cas, aucune écriture ni verrou
        $this->assertCount(3, $report->accounts);
        $this->assertSame(5, $few);
        $this->assertSame($few, $many);
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

    private function savedUser(string $id, string $status = 'ACTIVE'): User
    {
        $builder = new UserBuilder()->withId($id);
        if ($status === 'PENDING') {
            $builder->isPending();
        }
        $user = $builder->build();
        $this->persister->saveUser($user);

        return $user;
    }

    /** Une farine à l'utilisateur : gramme dans les recettes, kilo à l'achat. */
    private function savedFlour(User $user): Ingredient
    {
        $flour = new IngredientBuilder()->isFlour()->withCreator($user, true)->build();
        $this->persister->saveIngredient($flour);

        return $flour;
    }

    /** Une recette de l'utilisateur avec deux lignes de cet ingrédient : l'une dans `$unit`, l'autre dans `$otherUnit`. */
    private function savedRecipe(User $author, Ingredient $ingredient, string $unit, string $otherUnit): void
    {
        $recipe = new RecipeBuilder()->withAuthor($author)
            ->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit($this->unit($unit))->withQuantity(1))
            ->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit($this->unit($otherUnit))->withQuantity(2))
            ->build();
        $this->persister->saveRecipe($recipe);
    }

    private function unit(string $slug): UnitBuilder
    {
        return match ($slug) {
            'gramme' => new UnitBuilder()->isGramme(),
            'kilo' => new UnitBuilder()->isKilo(),
            default => new UnitBuilder()->isSoupSpoon(),
        };
    }
}
