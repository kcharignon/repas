<?php

namespace Repas\Tests\User\OutputAdapter;


use Repas\Shared\Application\Interface\UserDefaultServing;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Infrastructure\Account\UserDefaultServingPostgreSQL;

/**
 * Le nombre de personnes par défaut d'un compte, lu sans charger le compte : le port que la commande vocale (ADR 0029 décision 13) utilise pour
 * planifier un repas quand l'utilisateur ne dit pas pour combien.
 */
class UserDefaultServingTest extends DatabaseTestCase
{
    private UserRepository $users;
    private UserDefaultServing $servings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = static::getContainer()->get(UserRepository::class);
        $this->servings = static::getContainer()->get(UserDefaultServing::class);
    }

    public function testThePortIsWiredOnTheUserImplementation(): void
    {
        $this->assertInstanceOf(UserDefaultServingPostgreSQL::class, $this->servings);
    }

    public function testItReadsTheServingsOfEachAccount(): void
    {
        // Given
        $two = new UserBuilder()->withServing(2)->build();
        $six = new UserBuilder()->withServing(6)->build();
        $this->users->save($two);
        $this->users->save($six);

        // Then
        $this->assertSame(2, $this->servings->of($two->getId()));
        $this->assertSame(6, $this->servings->of($six->getId()));
    }

    public function testTheAnswerFollowsTheSettingsOfTheAccount(): void
    {
        // Given
        $user = new UserBuilder()->withServing(4)->build();
        $this->users->save($user);
        $this->assertSame(4, $this->servings->of($user->getId()));

        // When : l'utilisateur change son réglage
        $user->update(5);
        $this->users->save($user);
        $this->entityManager->clear();

        // Then
        $this->assertSame(5, $this->servings->of($user->getId()));
    }

    public function testAnUnknownAccountGetsTheFallbackNeverAFailure(): void
    {
        $this->assertSame(UserDefaultServing::FALLBACK, $this->servings->of('unknown-user'));
    }
}
