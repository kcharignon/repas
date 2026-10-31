<?php

namespace Repas\Tests\Voice\Acl;

use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientCommand;
use Repas\Repas\Application\Command\RemoveSpokenIngredient\RemoveSpokenIngredientCommand;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Tests\Helper\RepasForVoice;
use Repas\Voice\Domain\Model\AddedItem;
use Repas\Voice\Domain\Model\ListedLine;
use Repas\Voice\Domain\Model\ListedMeal;
use Repas\Voice\Domain\Model\RemovedItem;
use Repas\Voice\Domain\Model\SpokenListLookupResult;
use Repas\Voice\Domain\Model\SpokenMealLookupResult;

/**
 * La transposition des couches anticorruption de la liste de courses (ADR 0035) : ce que `Repas` rend (article ajouté ou retiré, ligne trouvée, lignes
 * qui se valent, repas planifié) devient les types de `Voice`, côté écriture comme côté lecture. La recherche et le classement sont prouvés dans `Repas`,
 * les dialogues par les tests des handlers.
 */
class RepasListAdaptersTest extends TestCase
{
    private RepasForVoice $repas;

    protected function setUp(): void
    {
        $this->repas = new RepasForVoice();
    }

    private static function line(string $slug, string $name, string $departmentName, float $quantity = 3.0): ListedLine
    {
        return new ListedLine($slug, $name, strtolower($departmentName), $departmentName, $quantity, 'paquet', 'paquet');
    }

    public function testAnAddedItemIsGivenWithEverythingTheListDid(): void
    {
        // Given
        $this->repas->willAdd(new AddedItem(true, true, 3.0, 'kilo', 'kilo', true, true, 2.0));

        // When
        $added = $this->repas->itemsPort()->addItem('user-1', 'pomme-de-terre', 2, 'kilo');

        // Then : la commande de `Repas` porte l'utilisateur, le slug, la quantité et l'unité dites
        $this->assertEquals(new AddedItem(true, true, 3.0, 'kilo', 'kilo', true, true, 2.0), $added);
        $command = $this->repas->bus->dispatched()[0];
        $this->assertInstanceOf(AddSpokenIngredientCommand::class, $command);
        $this->assertSame(['user-1', 'pomme-de-terre', 2, 'kilo'], [$command->ownerId, $command->ingredientSlug, $command->quantity, $command->unitSlug]);
    }

    public function testARemovedItemIsGivenWithEverythingTheListDid(): void
    {
        // Given
        $this->repas->willRemove(new RemovedItem(true, 1.0, 2.0, 'paquet', 'paquet', true));

        // When
        $removed = $this->repas->itemsPort()->removeItem('user-1', 'croquette', 2, 'paquet');

        // Then
        $this->assertEquals(new RemovedItem(true, 1.0, 2.0, 'paquet', 'paquet', true), $removed);
        $command = $this->repas->bus->dispatched()[0];
        $this->assertInstanceOf(RemoveSpokenIngredientCommand::class, $command);
        $this->assertSame(['user-1', 'croquette', 2, 'paquet'], [$command->ownerId, $command->ingredientSlug, $command->quantity, $command->unitSlug]);
    }

    public function testTheErrorsOfRepasAreNotCaught(): void
    {
        // Given
        $this->repas->willFailToWriteTheList(ShoppingListException::invalidSpokenQuantity(100));

        // Then : la commande entière échoue, rien n'est écrit
        $this->expectExceptionObject(ShoppingListException::invalidSpokenQuantity(100));
        $this->repas->itemsPort()->addItem('user-1', 'pain', 100);
    }

    public function testNoActiveListAndASentListAreToldApartOnBothSides(): void
    {
        // When / Then : ni liste active ni liste envoyée
        $this->assertEquals(SpokenListLookupResult::noList(), $this->repas->lineLookupPort()->find('user-1', 'du pain'));
        $this->assertEquals(SpokenListLookupResult::noList(), $this->repas->lineLookupQueryPort()->find('user-1', 'du pain'));
        $this->assertEquals(SpokenMealLookupResult::noList(), $this->repas->mealLookupPort()->find('user-1', 'hachis'));

        // Given : une liste envoyée au drive seulement
        $this->repas->hasSentList();

        // Then : elle se dit à part, elle n'est jamais lue
        $this->assertEquals(SpokenListLookupResult::sentList(), $this->repas->lineLookupPort()->find('user-1', 'du pain'));
        $this->assertEquals(SpokenListLookupResult::sentList(), $this->repas->lineLookupQueryPort()->find('user-1', 'du pain'));
        $this->assertEquals(SpokenMealLookupResult::sentList(), $this->repas->mealLookupPort()->find('user-1', 'hachis'));
    }

    public function testAFoundLineIsGivenToTheDialogueOnBothSides(): void
    {
        // Given
        $croquette = self::line('croquette', 'Croquette', 'Animal');
        $this->repas->listHas($croquette);

        // When / Then : la même ligne, que l'on écrive ou que l'on lise
        $this->assertEquals(SpokenListLookupResult::found($croquette), $this->repas->lineLookupPort()->find('user-1', 'des croquettes'));
        $this->assertEquals(SpokenListLookupResult::found($croquette), $this->repas->lineLookupQueryPort()->find('user-1', 'des croquettes'));
    }

    public function testLinesThatTieAreGivenTogetherOnBothSides(): void
    {
        // Given : la tomate en fruit et légume et la tomate surgelée sont toutes deux dans la liste
        $fresh = self::line('tomate-fl', 'Tomate', 'Fruit-et-legume', 2.0);
        $frozen = self::line('tomate-sg', 'Tomate', 'Surgele', 1.0);
        $this->repas->listHas($fresh)->listHas($frozen);

        // When
        $written = $this->repas->lineLookupPort()->find('user-1', 'des tomates');
        $read = $this->repas->lineLookupQueryPort()->find('user-1', 'des tomates');

        // Then
        $this->assertTrue($written->isTied());
        $this->assertEquals($written, $read);
        $this->assertEqualsCanonicalizing([$fresh, $frozen], $read->lines);
    }

    public function testAKnownIngredientOutsideTheListIsGivenWithItsNameAndALostPhraseWithTheNearLines(): void
    {
        // Given : la liste porte une pâte brisée, l'utilisateur connaît aussi la tomate
        $pate = self::line('pate-brisee', 'Pâte brisée', 'Frais');
        $this->repas->listHas($pate)->owns('tomate', 'Tomate', 'Fruit-et-legume');

        // When
        $absent = $this->repas->lineLookupPort()->find('user-1', 'des tomates');
        $near = $this->repas->lineLookupQueryPort()->find('user-1', 'des pâtes à pizza');

        // Then
        $this->assertEquals(SpokenListLookupResult::notInList('Tomate'), $absent);
        $this->assertEquals(SpokenListLookupResult::notInList(null, [$pate]), $near);
    }

    public function testAPlannedMealIsGivenWithItsServingsAndTheCloseMealsAreProposed(): void
    {
        // Given
        $hachis = new ListedMeal('recipe-1', 'Hachis parmentier', 4);
        $this->repas->listPlans($hachis)->listPlans(new ListedMeal('recipe-2', 'Gratin dauphinois', 2));

        // When
        $found = $this->repas->mealLookupPort()->find('user-1', 'hachis parmentier');
        $none = $this->repas->mealLookupPort()->find('user-1', 'lasagnes');

        // Then
        $this->assertEquals(SpokenMealLookupResult::found($hachis), $found);
        $this->assertNull($none->meal);
        $this->assertTrue($none->hasList);
    }

    public function testThePhraseTheUserRememberedForARecipeIsBelieved(): void
    {
        // Given : « hachis » désigne la recette 1 pour cet utilisateur
        $hachis = new ListedMeal('recipe-1', 'Hachis parmentier', 4);
        $this->repas->listPlans($hachis)->listPlans(new ListedMeal('recipe-2', 'Hachis de boeuf', 2))->remembersRecipe('user-1', SpokenIngredientRanker::phraseKey('hachis'), 'recipe-1');

        // When / Then
        $this->assertEquals(SpokenMealLookupResult::found($hachis), $this->repas->mealLookupPort()->find('user-1', 'hachis'));
    }
}
