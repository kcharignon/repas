<?php

namespace Repas\Tests\Repas\Infrastructure\Voice;

use Repas\Shared\Application\Interface\SpokenIngredientResolver;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;

/**
 * Le port `SpokenIngredientResolver` câblé pour de vrai : adaptateur, chargeur des habitudes et classeur sur la base de test.
 */
class SpokenIngredientResolverFlowTest extends DatabaseTestCase
{
    public function testItResolvesAPhraseToAnIngredientOfTheUserOnly(): void
    {
        // Given : le lait est à l'autre utilisateur, le pain de l'utilisateur
        $resolver = static::getContainer()->get(SpokenIngredientResolver::class);
        $persister = new DataPersister(static::getContainer());
        $user = new UserBuilder()->build();
        $other = new UserBuilder()->build();
        $persister->saveUser($user);
        $persister->saveUser($other);
        $bread = new IngredientBuilder()->isBread()->withCreator($user, true)->build();
        $persister->saveIngredient($bread);
        $persister->saveIngredient(new IngredientBuilder()->isMilk()->withCreator($other, true)->build());

        // When
        $found = $resolver->resolve($user->getId(), 'du pain');
        $notFound = $resolver->resolve($user->getId(), 'du lait');

        // Then
        $this->assertSame([$bread->getSlug(), 'sure'], [$found?->slug, $found?->confidence]);
        $this->assertNull($notFound);
    }
}
