<?php

namespace Repas\Tests\User\Infrastructure\Security;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\MockCommandBus;
use Repas\User\Application\RecordUserLogin\RecordUserLoginCommand;
use Repas\User\Infrastructure\Security\UserLoginTracker;
use Symfony\Component\Security\Core\User\InMemoryUser;

class UserLoginTrackerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-20 10:15:00');
    }

    public function testAnAuthenticatedAccountToBeRecordedGivesOneCommand(): void
    {
        // Given
        $bus = new MockCommandBus();
        $user = new UserBuilder()->withId('user-id')->build();

        // When
        $this->tracker($bus)->authenticated($user);

        // Then : l'identifiant du compte authentifié, rien d'autre
        $this->assertEquals([new RecordUserLoginCommand('user-id')], $bus->dispatched());
    }

    public function testAnAccountSeenWithinTenMinutesDispatchesNothing(): void
    {
        // Given : la valeur déjà chargée par l'authentification suffit à décider, sans passer par le bus (zéro requête)
        $bus = new MockCommandBus();
        $user = new UserBuilder()->withLastLoginAt($this->now->modify('-3 minutes'))->build();

        // When
        $this->tracker($bus)->authenticated($user);

        // Then
        $this->assertSame([], $bus->dispatched());
    }

    public function testAnAccountThatIsNotActiveDispatchesNothing(): void
    {
        // Given
        $bus = new MockCommandBus();

        // When
        $this->tracker($bus)->authenticated(new UserBuilder()->isDisabled()->build());
        $this->tracker($bus)->authenticated(new UserBuilder()->isPending()->build());

        // Then
        $this->assertSame([], $bus->dispatched());
    }

    public function testAUserThatIsNotAnAccountOfTheApplicationIsIgnored(): void
    {
        // Given
        $bus = new MockCommandBus();

        // When
        $this->tracker($bus)->authenticated(new InMemoryUser('quelqu-un', null));

        // Then
        $this->assertSame([], $bus->dispatched());
    }

    public function testAFailureOfTheTrackingNeverFailsTheAuthentication(): void
    {
        // Given : la base répond une erreur à l'enregistrement
        $failingBus = new class implements CommandBusInterface {
            public function dispatch(object $command): mixed
            {
                throw new \RuntimeException('connexion à la base perdue');
            }
        };

        // When
        $this->tracker($failingBus)->authenticated(new UserBuilder()->build());

        // Then : aucune exception ne remonte (le renouvellement de session a déjà consommé son jeton)
        $this->addToAssertionCount(1);
    }

    private function tracker(CommandBusInterface $bus): UserLoginTracker
    {
        return new UserLoginTracker($bus, new FrozenClock($this->now), new NullLogger());
    }
}
