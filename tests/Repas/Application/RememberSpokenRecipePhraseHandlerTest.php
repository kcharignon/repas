<?php

namespace Repas\Tests\Repas\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RememberSpokenRecipePhrase\RememberSpokenRecipePhraseCommand;
use Repas\Repas\Application\Command\RememberSpokenRecipePhrase\RememberSpokenRecipePhraseHandler;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpokenRecipePhraseChoiceInMemoryRepository;

/**
 * Retenir ce que l'utilisateur a choisi pour une phrase de recette (ADR 0029 décision 13) : unitaire, avec les doubles en mémoire. La table réelle est
 * prouvée par `SpokenRecipePhraseChoiceRepositoryTest`, le parcours par `SpokenRecipeResolverFlowTest`.
 */
class RememberSpokenRecipePhraseHandlerTest extends TestCase
{
    private const string USER = 'user-1';
    private const string OTHER = 'user-2';

    private RecipeInMemoryRepository $recipes;
    private SpokenRecipePhraseChoiceInMemoryRepository $choices;
    private RememberSpokenRecipePhraseHandler $handler;

    protected function setUp(): void
    {
        $this->recipes = new RecipeInMemoryRepository();
        $this->choices = new SpokenRecipePhraseChoiceInMemoryRepository();
        $this->handler = new RememberSpokenRecipePhraseHandler($this->choices, $this->recipes, new FrozenClock(new DateTimeImmutable('2026-10-26 12:00:00')));
    }

    private function savedRecipeOf(string $userId, string $id): string
    {
        $this->recipes->save(new RecipeBuilder()->isPastaCarbonara()->withId($id)->withAuthor(new UserBuilder()->withId($userId))->build());

        return $id;
    }

    public function testItRemembersThePhraseUnderItsNormalizedKeyForTheRecipeOfTheUser(): void
    {
        // Given
        $id = $this->savedRecipeOf(self::USER, 'recipe-1');

        // When
        ($this->handler)(new RememberSpokenRecipePhraseCommand(self::USER, 'le Gratin de pâtes', $id));

        // Then : la clé est celle du classeur de noms (articles retirés), la date vient de l'horloge
        $rows = $this->choices->all();
        $this->assertCount(1, $rows);
        $this->assertSame([self::USER, 'gratin-pate', $id, 1], [$rows[0]['userId'], $rows[0]['phrase'], $rows[0]['recipeId'], $rows[0]['times']]);
        $this->assertSame('2026-10-26 12:00:00', $rows[0]['updatedAt']->format('Y-m-d H:i:s'));
    }

    public function testChoosingTheSameRecipeAgainCountsAndAnotherOneReplaces(): void
    {
        // Given
        $first = $this->savedRecipeOf(self::USER, 'recipe-1');
        $second = $this->savedRecipeOf(self::USER, 'recipe-2');

        // When
        ($this->handler)(new RememberSpokenRecipePhraseCommand(self::USER, 'gratin', $first));
        ($this->handler)(new RememberSpokenRecipePhraseCommand(self::USER, 'les gratins', $first));
        $afterTwice = $this->choices->all()[0]['times'];
        ($this->handler)(new RememberSpokenRecipePhraseCommand(self::USER, 'gratin', $second));

        // Then : « gratins » et « gratin » sont la même phrase ; le dernier choix l'emporte et repart à 1
        $this->assertSame(2, $afterTwice);
        $rows = $this->choices->all();
        $this->assertCount(1, $rows);
        $this->assertSame([$second, 1], [$rows[0]['recipeId'], $rows[0]['times']]);
    }

    public function testTheRecipeOfAnotherUserIsRefusedAndNothingIsWritten(): void
    {
        // Given
        $id = $this->savedRecipeOf(self::OTHER, 'recipe-of-other');

        // Then
        $this->expectExceptionObject(RecipeException::notFound($id));
        try {
            ($this->handler)(new RememberSpokenRecipePhraseCommand(self::USER, 'gratin', $id));
        } finally {
            $this->assertSame([], $this->choices->all());
        }
    }

    public function testAnUnknownRecipeIsRefusedAndNothingIsWritten(): void
    {
        // Then
        $this->expectException(RecipeException::class);
        try {
            ($this->handler)(new RememberSpokenRecipePhraseCommand(self::USER, 'gratin', 'unknown'));
        } finally {
            $this->assertSame([], $this->choices->all());
        }
    }

    public function testAPhraseWithoutAnyMeaningfulWordWritesNothingAndChecksNothing(): void
    {
        // When : « de la » n'a aucun mot qui compte, même avec une recette inconnue
        ($this->handler)(new RememberSpokenRecipePhraseCommand(self::USER, 'de la', 'unknown'));

        // Then
        $this->assertSame([], $this->choices->all());
    }
}
