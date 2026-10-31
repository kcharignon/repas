<?php

namespace Repas\Tests\Repas\OpenHostService;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RememberSpokenRecipePhrase\RememberSpokenRecipePhraseCommand;
use Repas\Repas\Application\OpenHostService\RecipePhraseCommandOHS;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\RecipeChoice;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Repas\Domain\Service\RecipePhraseResolver;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Tests\Helper\InMemoryRepository\RecipePhraseCandidatesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpokenRecipePhraseChoiceInMemoryRepository;
use Repas\Tests\Helper\MockCommandBus;

/**
 * L'Open Host Service seulement (dépôts en mémoire et bus simulé) : il charge les recettes de l'utilisateur UNE fois, lit la mémoire de phrase AVANT le
 * classement, laisse le classeur choisir ou proposer et rend des modèles de `Repas`. Le classement lui-même est prouvé par `SpokenIngredientRankerTest`,
 * la lecture des recettes et de la mémoire par les tests des Repositories, le parcours complet par `RecipePhraseCommandOHSFlowTest`.
 */
class RecipePhraseCommandOHSTest extends TestCase
{
    private MockCommandBus $bus;
    private RecipePhraseCandidatesInMemoryRepository $candidates;
    private SpokenRecipePhraseChoiceInMemoryRepository $memory;

    protected function setUp(): void
    {
        $this->bus = new MockCommandBus();
    }

    /**
     * @param list<array{string, string}> $recipes `[identifiant, nom]` de chaque recette de l'utilisateur
     * @param array<string, string>       $remembered clé de phrase => identifiant de recette
     */
    private function ohs(array $recipes, array $remembered = []): RecipePhraseCommandOHS
    {
        $this->candidates = new RecipePhraseCandidatesInMemoryRepository(array_map(
            static fn(array $recipe): SpokenCandidate => new SpokenCandidate($recipe[0], mb_strtolower($recipe[1]), $recipe[1], ''),
            $recipes,
        ));
        $this->memory = new SpokenRecipePhraseChoiceInMemoryRepository();
        foreach ($remembered as $key => $id) {
            $this->memory->remember('user-id', (string) $key, $id, new DateTimeImmutable('2026-10-26 10:00:00'));
        }
        $this->memory->reads = [];

        return new RecipePhraseCommandOHS($this->candidates, $this->memory, new RecipePhraseResolver(), $this->bus);
    }

    public function testItLoadsTheRecipesOfTheUserOnceAndRanksThem(): void
    {
        // Given
        $ohs = $this->ohs([['r1', 'Gratin dauphinois'], ['r2', 'Lasagnes']]);

        // When
        $resolution = $ohs->resolveOrSuggest('user-id', 'le gratin dauphinois');

        // Then
        $this->assertEquals(new RecipeChoice('r1', 'Gratin dauphinois'), $resolution->recipe);
        $this->assertSame([], $resolution->suggestions);
        $this->assertSame(['user-id'], $this->candidates->calls);
    }

    public function testAUserWithoutAnyRecipeIsToldSoAndTheMemoryIsNotRead(): void
    {
        // Given
        $ohs = $this->ohs([]);

        // When
        $resolution = $ohs->resolveOrSuggest('user-id', 'gratin');

        // Then
        $this->assertNull($resolution->recipe);
        $this->assertSame([], $resolution->suggestions);
        $this->assertFalse($resolution->hasRecipes);
        $this->assertSame([], $this->memory->reads);
    }

    public function testTheMemoryOfThePhraseIsReadBeforeTheRankingAndWins(): void
    {
        // Given : « le plat du dimanche » ne ressemble à rien, mais il a déjà dit que c'était le hachis
        $ohs = $this->ohs([['r1', 'Hachis parmentier'], ['r2', 'Lasagnes']], [SpokenIngredientRanker::phraseKey('le plat du dimanche') => 'r1']);

        // When
        $resolution = $ohs->resolveOrSuggest('user-id', 'le plat du dimanche');

        // Then
        $this->assertSame('r1', $resolution->recipe?->id);
        $this->assertCount(1, $this->memory->reads);
    }

    public function testNothingSureGivesTheThreeClosestRecipesEvenFarOnes(): void
    {
        // Given
        $ohs = $this->ohs([['r1', 'Lasagnes'], ['r2', 'Hachis parmentier'], ['r3', 'Blanquette de dinde'], ['r4', 'Gratin dauphinois']]);

        // When
        $resolution = $ohs->resolveOrSuggest('user-id', 'blanquette de volaille');

        // Then : jamais un silence, jamais deviné
        $this->assertNull($resolution->recipe);
        $this->assertTrue($resolution->hasRecipes);
        $this->assertCount(3, $resolution->suggestions);
        $this->assertSame('r3', $resolution->suggestions[0]->id);
    }

    public function testTwoRecipesThatBothFitAreProposedNeverGuessed(): void
    {
        // Given
        $ohs = $this->ohs([['r1', 'Gratin dauphinois'], ['r2', 'Gratin de pâtes']]);

        // When
        $resolution = $ohs->resolveOrSuggest('user-id', 'gratin');

        // Then
        $this->assertNull($resolution->recipe);
        $this->assertEqualsCanonicalizing([new RecipeChoice('r1', 'Gratin dauphinois'), new RecipeChoice('r2', 'Gratin de pâtes')], $resolution->suggestions);
    }

    public function testRememberingSendsTheCommandOnTheBus(): void
    {
        // Given
        $ohs = $this->ohs([]);

        // When
        $ohs->remember('user-id', 'le plat du dimanche', 'r1');

        // Then
        $this->assertEquals([new RememberSpokenRecipePhraseCommand('user-id', 'le plat du dimanche', 'r1')], $this->bus->dispatched());
    }

    public function testADomainErrorOfTheHandlerIsNotHidden(): void
    {
        // Given
        $this->bus->willThrow(RememberSpokenRecipePhraseCommand::class, RecipeException::notFound('r9'));
        $ohs = $this->ohs([]);

        // Then
        $this->expectExceptionObject(RecipeException::notFound('r9'));

        // When
        $ohs->remember('user-id', 'gratin', 'r9');
    }
}
