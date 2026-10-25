<?php

namespace Repas\Tests\Repas\Infrastructure\Voice;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Shared\Application\Interface\SpokenIngredientResolver;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Model\User;

/**
 * Le port `SpokenIngredientResolver` câblé pour de vrai : adaptateur, chargeur des habitudes, mémoire de phrase, bus transactionnel et classeur
 * sur la base de test.
 */
class SpokenIngredientResolverFlowTest extends DatabaseTestCase
{
    private SpokenIngredientResolver $resolver;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = static::getContainer()->get(SpokenIngredientResolver::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    public function testItResolvesAPhraseToAnIngredientOfTheUserOnly(): void
    {
        // Given : le lait est à l'autre utilisateur, le pain de l'utilisateur
        $user = $this->savedUser();
        $other = $this->savedUser();
        $bread = new IngredientBuilder()->isBread()->withCreator($user, true)->build();
        $this->persister->saveIngredient($bread);
        $this->persister->saveIngredient(new IngredientBuilder()->isMilk()->withCreator($other, true)->build());

        // When
        $found = $this->resolver->resolve($user->getId(), 'du pain');
        $notFound = $this->resolver->resolve($user->getId(), 'du lait');

        // Then
        $this->assertSame([$bread->getSlug(), 'sure'], [$found?->slug, $found?->confidence]);
        $this->assertNull($notFound);
    }

    public function testWhatTheUserChoseForAPhraseIsRememberedAndWinsNextTime(): void
    {
        // Given : « ciboulette » ne ressemble à aucun de ses ingrédients ; il dit que c'est le pain
        $user = $this->savedUser();
        $bread = new IngredientBuilder()->isBread()->withCreator($user, true)->build();
        $this->persister->saveIngredient($bread);
        $this->assertNull($this->resolver->resolve($user->getId(), 'de la ciboulette'));

        // When
        $this->resolver->remember($user->getId(), 'de la ciboulette', $bread->getSlug());
        $this->entityManager->clear();

        // Then : la même phrase, et sa variante au pluriel sans article, donnent le pain, sûr
        foreach (['de la ciboulette', 'ciboulettes', 'la ciboulette'] as $phrase) {
            $resolved = $this->resolver->resolve($user->getId(), $phrase);
            $this->assertSame([$bread->getSlug(), 'sure'], [$resolved?->slug, $resolved?->confidence], $phrase);
        }
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM spoken_phrase_choice'));
    }

    public function testTheMemoryOfAUserIsNeverUsedForAnother(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $bread = new IngredientBuilder()->isBread()->withCreator($user, true)->build();
        $this->persister->saveIngredient($bread);
        $this->persister->saveIngredient(new IngredientBuilder()->isBread()->withCreator($other, true)->build());
        $this->resolver->remember($user->getId(), 'ciboulette', $bread->getSlug());

        // Then
        $this->assertNull($this->resolver->resolve($other->getId(), 'ciboulette'));
    }

    public function testTheIngredientOfAnotherUserCannotBeRememberedAndNothingIsWritten(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $theirBread = new IngredientBuilder()->isBread()->withCreator($other, true)->build();
        $this->persister->saveIngredient($theirBread);

        // Then : 404 comme l'ingrédient d'un autre partout, et la table reste vide
        try {
            $this->resolver->remember($user->getId(), 'ciboulette', $theirBread->getSlug());
            $this->fail('IngredientException attendue.');
        } catch (IngredientException) {
        }
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM spoken_phrase_choice'));
    }

    public function testAMemoryForADeletedIngredientFallsBackToTheRanking(): void
    {
        // Given : la mémoire désigne un ingrédient qui n'existe plus (suppression directe, sans passer par le cas d'usage)
        $user = $this->savedUser();
        $bread = new IngredientBuilder()->isBread()->withCreator($user, true)->build();
        $this->persister->saveIngredient($bread);
        $this->entityManager->getConnection()->insert('spoken_phrase_choice', ['user_id' => $user->getId(), 'phrase' => 'pain', 'ingredient_slug' => 'disparu', 'times' => 1, 'updated_at' => '2026-10-25 10:00:00']);

        // When
        $resolved = $this->resolver->resolve($user->getId(), 'du pain');

        // Then
        $this->assertSame($bread->getSlug(), $resolved?->slug);
    }

    public function testItSuggestsTheClosestIngredientsOfTheUserOnly(): void
    {
        // Given : un sirop à lui, un autre sirop à quelqu'un d'autre
        $user = $this->savedUser();
        $other = $this->savedUser();
        $syrup = new IngredientBuilder()->withName('Sirop fruit rouge')->withCreator($user, true)->build();
        $this->persister->saveIngredient($syrup);
        $this->persister->saveIngredient(new IngredientBuilder()->withName('Sirop de menthe glaciale')->withCreator($other, true)->build());

        // When
        $suggestions = $this->resolver->suggest($user->getId(), 'sirop de menthe');

        // Then
        $this->assertSame([$syrup->getSlug()], array_map(static fn($suggestion): string => $suggestion->slug, $suggestions));
        $this->assertSame([], $this->resolver->suggest($user->getId(), 'zébulon'));
    }
}
