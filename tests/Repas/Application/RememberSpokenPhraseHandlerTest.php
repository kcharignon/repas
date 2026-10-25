<?php

namespace Repas\Tests\Repas\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RememberSpokenPhrase\RememberSpokenPhraseCommand;
use Repas\Repas\Application\Command\RememberSpokenPhrase\RememberSpokenPhraseHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpokenPhraseChoiceInMemoryRepository;

/**
 * Retenir ce que l'utilisateur a choisi pour une phrase (ADR 0029 décision 11) : unitaire, avec les doubles en mémoire. La table réelle est
 * prouvée par `SpokenPhraseChoiceRepositoryTest`, le parcours par `SpokenIngredientResolverFlowTest`.
 */
class RememberSpokenPhraseHandlerTest extends TestCase
{
    private const string USER = 'user-1';
    private const string OTHER = 'user-2';

    private IngredientInMemoryRepository $ingredients;
    private IngredientAliasInMemoryRepository $aliases;
    private SpokenPhraseChoiceInMemoryRepository $choices;
    private RememberSpokenPhraseHandler $handler;

    protected function setUp(): void
    {
        $this->ingredients = new IngredientInMemoryRepository();
        $this->aliases = new IngredientAliasInMemoryRepository();
        $this->choices = new SpokenPhraseChoiceInMemoryRepository();
        $this->handler = new RememberSpokenPhraseHandler(
            $this->choices,
            $this->ingredients,
            new IngredientSlugResolver($this->aliases),
            new FrozenClock(new DateTimeImmutable('2026-10-25 12:00:00')),
        );
    }

    private function saveIngredientOf(string $userId, string $name): string
    {
        $ingredient = new IngredientBuilder()->withName($name)->withCreator(new UserBuilder()->withId($userId)->build(), true)->build();
        $this->ingredients->save($ingredient);

        return $ingredient->getSlug();
    }

    public function testItRemembersThePhraseUnderItsNormalizedKeyForTheIngredientOfTheUser(): void
    {
        // Given
        $slug = $this->saveIngredientOf(self::USER, 'Persil');

        // When
        ($this->handler)(new RememberSpokenPhraseCommand(self::USER, 'de la Ciboulette', $slug));

        // Then : la clé est « ciboulette » (articles retirés), la date vient de l'horloge
        $rows = $this->choices->all();
        $this->assertCount(1, $rows);
        $this->assertSame([self::USER, 'ciboulette', $slug, 1], [$rows[0]['userId'], $rows[0]['phrase'], $rows[0]['slug'], $rows[0]['times']]);
        $this->assertSame('2026-10-25 12:00:00', $rows[0]['updatedAt']->format('Y-m-d H:i:s'));
    }

    public function testChoosingTheSameIngredientAgainCountsAndAnotherOneReplaces(): void
    {
        // Given
        $parsley = $this->saveIngredientOf(self::USER, 'Persil');
        $basil = $this->saveIngredientOf(self::USER, 'Basilic');

        // When
        ($this->handler)(new RememberSpokenPhraseCommand(self::USER, 'ciboulette', $parsley));
        ($this->handler)(new RememberSpokenPhraseCommand(self::USER, 'les ciboulettes', $parsley));
        $afterTwice = $this->choices->all()[0]['times'];
        ($this->handler)(new RememberSpokenPhraseCommand(self::USER, 'ciboulette', $basil));

        // Then : « ciboulettes » et « ciboulette » sont la même phrase ; le dernier choix l'emporte et repart à 1
        $this->assertSame(2, $afterTwice);
        $rows = $this->choices->all();
        $this->assertCount(1, $rows);
        $this->assertSame([$basil, 1], [$rows[0]['slug'], $rows[0]['times']]);
    }

    public function testTheIngredientOfAnotherUserIsRefusedAndNothingIsWritten(): void
    {
        // Given
        $slug = $this->saveIngredientOf(self::OTHER, 'Persil');

        // Then
        $this->expectExceptionObject(IngredientException::notFound($slug));
        try {
            ($this->handler)(new RememberSpokenPhraseCommand(self::USER, 'ciboulette', $slug));
        } finally {
            $this->assertSame([], $this->choices->all());
        }
    }

    public function testAnUnknownIngredientIsRefusedAndNothingIsWritten(): void
    {
        // Then
        $this->expectException(IngredientException::class);
        try {
            ($this->handler)(new RememberSpokenPhraseCommand(self::USER, 'ciboulette', 'inconnu'));
        } finally {
            $this->assertSame([], $this->choices->all());
        }
    }

    public function testAnOldSlugIsTranslatedByTheAliasesOfTheUser(): void
    {
        // Given : « ancien-persil » est l'alias de son persil actuel
        $slug = $this->saveIngredientOf(self::USER, 'Persil');
        $this->aliases->save(IngredientAlias::create(self::USER, 'ancien-persil', $slug, AliasKind::MERGE, 'merge', new DateTimeImmutable()));

        // When
        ($this->handler)(new RememberSpokenPhraseCommand(self::USER, 'ciboulette', 'ancien-persil'));

        // Then
        $this->assertSame($slug, $this->choices->all()[0]['slug']);
    }

    public function testAPhraseWithoutAnyMeaningfulWordWritesNothingAndChecksNothing(): void
    {
        // When : « de la » n'a aucun mot qui compte, même avec un slug inconnu
        ($this->handler)(new RememberSpokenPhraseCommand(self::USER, 'de la', 'inconnu'));

        // Then
        $this->assertSame([], $this->choices->all());
    }
}
