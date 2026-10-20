<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\IngredientMergeLoader;
use Repas\Repas\Application\Query\GetMergePreview\MergeIngredientDTO;
use Repas\Repas\Application\Query\GetMergePreview\MergeUnitDTO;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\MergeDataset;
use Repas\User\Domain\Model\User;

/**
 * L'aperçu et les candidats de la fusion (ING-005) sur la vraie base. Le verdict de l'aperçu est comparé à celui de l'écriture dans
 * `MergePreviewMatchesTheMergeTest`.
 */
class IngredientMergePostgreSQLLoaderTest extends DatabaseTestCase
{
    private IngredientMergeLoader $loader;
    private MergeDataset $dataset;
    private User $user;
    private User $other;
    private Ingredient $kept;
    private Ingredient $absorbed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = static::getContainer()->get(IngredientMergeLoader::class);
        $this->dataset = new MergeDataset($this->entityManager, new DataPersister(static::getContainer()));
        $this->dataset->units();
        $this->user = $this->dataset->user();
        $this->other = $this->dataset->user();
        $this->kept = $this->dataset->ingredient('Tomate', $this->user);
        $this->absorbed = $this->dataset->ingredient('Tomates', $this->user);
        $this->dataset->conversion($this->kept);
    }

    private function preview(?Ingredient $absorbed = null, ?Ingredient $kept = null): \Repas\Repas\Application\Query\GetMergePreview\MergePreviewDTO
    {
        return $this->loader->preview($this->user->getId(), ($absorbed ?? $this->absorbed)->getSlug(), ($kept ?? $this->kept)->getSlug());
    }

    public function testThePreviewTellsWhatTheMergeWouldMove(): void
    {
        // Given : « Tomates » dans 2 recettes et 2 listes, un choix du drive (« Tomate » n'en a pas)
        $b = $this->absorbed->getSlug();
        $user = $this->user->getId();
        $this->dataset->recipe('r1', $user, [[$b, 100.0, 'gramme']]);
        $this->dataset->recipe('r2', $user, [[$b, 1.0, 'kilo'], [$b, 2.0, 'unite']]);
        $this->dataset->shoppingList('l1', $user, rows: [['x1', $b, 1.0, 'kilo']]);
        $this->dataset->shoppingList('l2', $user, items: [['y2', $b, 2.0, 'kilo']]);
        $this->dataset->choice('c1', $user, $b);

        // When
        $preview = $this->preview();

        // Then
        $this->assertEquals(new MergeIngredientDTO($this->kept->getSlug(), 'Tomate', 'cereale', 'céréale', 'kilo'), $preview->keep);
        $this->assertEquals(new MergeIngredientDTO($b, 'Tomates', 'cereale', 'céréale', 'kilo'), $preview->absorbed);
        $this->assertSame([2, 2, 1, false, false, [], null, true], [$preview->recipeCount, $preview->listCount, $preview->driveChoiceCount, $preview->departmentChanges, $preview->listQuantitiesConverted, $preview->missingUnits, $preview->blockedBy, $preview->canMerge]);
    }

    public function testThePreviewCountsOnlyTheChoicesThatPassOverNotTheOnesThatAreDropped(): void
    {
        // Given : « Tomates » a un choix dans deux magasins ; « Tomate » a déjà le sien dans le premier
        $this->dataset->choice('kept-1', $this->user->getId(), $this->kept->getSlug(), '111111');
        $this->dataset->choice('absorbed-1', $this->user->getId(), $this->absorbed->getSlug(), '111111');
        $this->dataset->choice('absorbed-2', $this->user->getId(), $this->absorbed->getSlug(), '222222');

        // When / Then
        $this->assertSame(1, $this->preview()->driveChoiceCount);
    }

    public function testThePreviewSaysWhenTheDepartmentOfTheKeptIngredientWins(): void
    {
        // Given
        $frozen = $this->dataset->ingredient('Tomates surgelées', $this->user, department: new DepartmentBuilder()->isMeat());

        // When
        $preview = $this->preview($frozen);

        // Then
        $this->assertTrue($preview->departmentChanges);
        $this->assertSame(['viande', 'cereale'], [$preview->absorbed->departmentSlug, $preview->keep->departmentSlug]);
    }

    public function testThePreviewSaysWhenTheListQuantitiesAreConverted(): void
    {
        // Given : « Tomate » s'achète au gramme, « Tomates » au kilo ; une ligne de liste en kilo
        $grams = $this->dataset->ingredient('Tomate au gramme', $this->user, new UnitBuilder()->isGramme());
        $this->dataset->shoppingList('l1', $this->user->getId(), rows: [['x1', $this->absorbed->getSlug(), 1.0, 'kilo']]);

        // When
        $preview = $this->preview($this->absorbed, $grams);

        // Then : le kilo se convertit en gramme (conversion commune), la quantité sera ré-exprimée
        $this->assertTrue($preview->canMerge);
        $this->assertTrue($preview->listQuantitiesConverted);
        $this->assertSame('gramme', $preview->keep->purchaseUnitName);
    }

    public function testThePreviewListsTheUnitsTheKeptIngredientCannotConvert(): void
    {
        // Given : une recette de « Tomates » en tranches, une autre en litres ; « Tomate » ne sait convertir ni l'une ni l'autre
        $this->dataset->recipe('r1', $this->user->getId(), [[$this->absorbed->getSlug(), 2.0, 'tranche'], [$this->absorbed->getSlug(), 1.0, 'litre'], [$this->absorbed->getSlug(), 1.0, 'gramme']]);

        // When
        $preview = $this->preview();

        // Then : refusée, avec le nom des unités, triées
        $this->assertFalse($preview->canMerge);
        $this->assertSame('UNIT_MISSING', $preview->blockedBy);
        $this->assertEquals([new MergeUnitDTO('litre', 'litre'), new MergeUnitDTO('tranche', 'tranche')], $preview->missingUnits);
    }

    public function testThePreviewRefusesASentListAndStillListsTheMissingUnits(): void
    {
        // Given
        $this->dataset->shoppingList('sent', $this->user->getId(), 'SENT', rows: [['x1', $this->kept->getSlug(), 1.0, 'kilo']]);
        $this->dataset->recipe('r1', $this->user->getId(), [[$this->absorbed->getSlug(), 2.0, 'tranche']]);

        // When
        $preview = $this->preview();

        // Then : la liste envoyée passe avant, l'unité qui manque est quand même connue
        $this->assertSame(['IN_SENT_LIST', false], [$preview->blockedBy, $preview->canMerge]);
        $this->assertEquals([new MergeUnitDTO('tranche', 'tranche')], $preview->missingUnits);
    }

    public function testThePreviewRefusesALineOfAnotherUserOrAChoiceOfAnotherUser(): void
    {
        foreach ([
            'une recette' => fn() => $this->dataset->recipe('legacy', $this->other->getId(), [[$this->absorbed->getSlug(), 1.0, 'gramme']]),
            'un choix du drive' => fn() => $this->dataset->choice('legacy-choice', $this->other->getId(), $this->absorbed->getSlug()),
        ] as $label => $arrange) {
            // Given
            $arrange();

            // When
            $preview = $this->preview();

            // Then
            $this->assertSame(['USED_BY_ANOTHER_USER', false], [$preview->blockedBy, $preview->canMerge], $label);
        }
    }

    public function testThePreviewRefusesTheSameIngredientAndTheCommonOnes(): void
    {
        // Given
        $common = new IngredientBuilder()->withName('Tomate commune')->withDepartment(new DepartmentBuilder()->isCereal())->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())->build();
        new DataPersister(static::getContainer())->saveIngredient($common);

        // Then : les deux ingrédients sont connus, le verdict est dans le corps
        $same = $this->preview($this->absorbed, $this->absorbed);
        $this->assertSame(['SAME_INGREDIENT', false], [$same->blockedBy, $same->canMerge]);
        $this->assertSame('COMMON_INGREDIENT', $this->preview($common, $this->kept)->blockedBy);
        $this->assertSame('COMMON_INGREDIENT', $this->preview($this->absorbed, $common)->blockedBy);
    }

    public function testThePreviewDoesNotKnowTheIngredientOfAnotherUserOrAnUnknownSlug(): void
    {
        // Given
        $theirs = $this->dataset->ingredient('Tomate du voisin', $this->other);

        foreach ([[$theirs->getSlug(), $this->kept->getSlug()], [$this->absorbed->getSlug(), $theirs->getSlug()], ['inconnu', $this->kept->getSlug()], [$this->absorbed->getSlug(), 'inconnu']] as [$absorbed, $kept]) {
            try {
                // When
                $this->loader->preview($this->user->getId(), $absorbed, $kept);
                $this->fail('un ingrédient inconnu ou à un autre');
            } catch (IngredientException $exception) {
                // Then
                $this->assertSame(404, $exception->getCode());
            }
        }
    }

    public function testThePreviewWritesNothing(): void
    {
        // Given
        $this->dataset->recipe('r1', $this->user->getId(), [[$this->absorbed->getSlug(), 2.0, 'gramme']]);
        $this->dataset->shoppingList('l1', $this->user->getId(), rows: [['x1', $this->absorbed->getSlug(), 1.0, 'kilo']]);
        $this->dataset->choice('c1', $this->user->getId(), $this->absorbed->getSlug());
        $before = $this->dataset->tableHashes();

        // When
        $this->preview();

        // Then
        $this->assertSame($before, $this->dataset->tableHashes());
    }

    public function testTheCandidatesAreTheIngredientsOfTheUserSameDepartmentFirstTheClosestFirst(): void
    {
        // Given : le même rayon (céréales) et un autre ; un commun, l'ingrédient d'un autre et l'ingrédient lui-même n'en sont pas
        $user = $this->user;
        $this->dataset->ingredient('Poivre noir', $user, department: new DepartmentBuilder()->isMeat());
        $this->dataset->ingredient('Tomate cerise', $user);
        $this->dataset->ingredient('Tomatte', $user, department: new DepartmentBuilder()->isMeat());
        $this->dataset->ingredient('Concombre', $user);
        $this->dataset->ingredient('Tomate de quelqu un d autre', $this->other);
        new DataPersister(static::getContainer())->saveIngredient(new IngredientBuilder()->withName('Tomate commune')->withDepartment(new DepartmentBuilder()->isCereal())->build());

        // When : on fusionne « Tomates » dans l'un d'eux
        $candidates = $this->loader->candidates($user->getId(), $this->absorbed->getSlug());

        // Then : « Tomate » (même nom au pluriel près), « Concombre » et « Tomate cerise » du même rayon, puis les autres rayons
        $this->assertSame(['Tomate', 'Concombre', 'Tomate cerise', 'Tomatte', 'Poivre noir'], array_map(static fn($candidate): string => $candidate->name, $candidates));
        $this->assertSame([true, true, true, false, false], array_map(static fn($candidate): bool => $candidate->sameDepartment, $candidates));
        // « Très proche » : le même nom (au pluriel près) ou une faute de frappe, même hors du rayon
        $this->assertSame([true, false, false, true, false], array_map(static fn($candidate): bool => $candidate->veryClose, $candidates));
        $this->assertSame(['céréale', 'céréale', 'céréale', 'viande', 'viande'], array_map(static fn($candidate): string => $candidate->departmentName, $candidates));
        $this->assertSame(['kilo', 'kilo', 'kilo', 'kilo', 'kilo'], array_map(static fn($candidate): string => $candidate->purchaseUnitName, $candidates));
    }

    public function testTheCandidatesShowHowManyRecipesAndListsUseThem(): void
    {
        // Given : « Tomate » dans 2 recettes et 1 liste (une ligne et un ajout), « Concombre » dans aucune
        $a = $this->kept->getSlug();
        $this->dataset->ingredient('Concombre', $this->user);
        $this->dataset->recipe('r1', $this->user->getId(), [[$a, 1.0, 'kilo'], [$a, 2.0, 'kilo']]);
        $this->dataset->recipe('r2', $this->user->getId(), [[$a, 1.0, 'kilo']]);
        $this->dataset->recipe('r3', $this->other->getId(), [[$a, 1.0, 'kilo']]);
        $this->dataset->shoppingList('l1', $this->user->getId(), rows: [['x1', $a, 1.0, 'kilo']], items: [['y1', $a, 1.0, 'kilo']]);

        // When
        $candidates = $this->loader->candidates($this->user->getId(), $this->absorbed->getSlug());

        // Then
        $counts = array_column(array_map(static fn($candidate): array => [$candidate->name, [$candidate->recipeCount, $candidate->listCount]], $candidates), 1, 0);
        $this->assertSame(['Tomate' => [2, 1], 'Concombre' => [0, 0]], $counts);
    }

    public function testTheSearchKeepsTheIngredientsWhoseNameContainsTheText(): void
    {
        // Given
        $this->dataset->ingredient('Concombre', $this->user);
        $this->dataset->ingredient('Tomate cerise', $this->user);

        // When
        $candidates = $this->loader->candidates($this->user->getId(), $this->absorbed->getSlug(), 'TOMÂT');

        // Then : sans tenir compte de la casse ni des accents
        $this->assertSame(['Tomate', 'Tomate cerise'], array_map(static fn($candidate): string => $candidate->name, $candidates));
        $this->assertSame([], $this->loader->candidates($this->user->getId(), $this->absorbed->getSlug(), 'zzz'));
        $this->assertCount(3, $this->loader->candidates($this->user->getId(), $this->absorbed->getSlug(), '!!!'), 'une recherche sans lettre ni chiffre ne filtre rien');
    }

    public function testTheCandidatesAreLimitedToThirty(): void
    {
        // Given : 35 ingrédients de plus
        for ($i = 0; $i < 35; ++$i) {
            $this->dataset->ingredient(sprintf('Legume %02d', $i), $this->user);
        }

        // When
        $candidates = $this->loader->candidates($this->user->getId(), $this->absorbed->getSlug());

        // Then : les trente premiers dans l'ordre (« Tomate » est le plus proche)
        $this->assertCount(30, $candidates);
        $this->assertSame('Tomate', $candidates[0]->name);
    }

    public function testTheCandidatesOfACommonOrOfSomeoneElsesIngredientAreRefused(): void
    {
        // Given
        $theirs = $this->dataset->ingredient('Tomate du voisin', $this->other);
        $common = new IngredientBuilder()->withName('Tomate commune')->withDepartment(new DepartmentBuilder()->isCereal())->build();
        new DataPersister(static::getContainer())->saveIngredient($common);

        // Then
        try {
            $this->loader->candidates($this->user->getId(), $theirs->getSlug());
            $this->fail('l\'ingrédient d\'un autre est inconnu');
        } catch (IngredientException $exception) {
            $this->assertSame(404, $exception->getCode());
        }
        try {
            $this->loader->candidates($this->user->getId(), $common->getSlug());
            $this->fail('un commun ne se fusionne pas');
        } catch (IngredientException $exception) {
            $this->assertSame(['CANNOT_MERGE_COMMON_INGREDIENT', 422], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->expectExceptionObject(IngredientException::notFound('inconnu'));
        $this->loader->candidates($this->user->getId(), 'inconnu');
    }
}
