<?php

namespace Repas\Tests\User\Infrastructure\Security;


use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Security\JwtPasswordFingerprintListener;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class JwtPasswordFingerprintListenerTest extends TestCase
{
    private JwtPasswordFingerprintListener $listener;

    protected function setUp(): void
    {
        $this->listener = new JwtPasswordFingerprintListener('secret-de-test');
    }

    public function testTheTokenCarriesAFingerprintThatIsNotThePasswordHash(): void
    {
        // Given
        $user = new UserBuilder()->withPassword('hash-du-mot-de-passe')->build();
        $event = new JWTCreatedEvent(['username' => $user->getEmail()], $user);

        // When
        $this->listener->onJwtCreated($event);

        // Then
        $payload = $event->getData();
        $this->assertSame($user->getEmail(), $payload['username']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $payload['pwd']);
        $this->assertStringNotContainsString('hash-du-mot-de-passe', json_encode($payload));
    }

    public function testATokenIssuedForTheCurrentPasswordIsAccepted(): void
    {
        // Given
        $user = new UserBuilder()->withPassword('hash-du-mot-de-passe')->build();
        $created = new JWTCreatedEvent([], $user);
        $this->listener->onJwtCreated($created);

        // When
        $this->listener->onJwtAuthenticated($this->authenticated($created->getData(), $user));

        // Then
        $this->addToAssertionCount(1);
    }

    public function testATokenIssuedBeforeAPasswordChangeIsRefused(): void
    {
        // Given
        $before = new UserBuilder()->withId('user-id')->withPassword('ancien-hash')->build();
        $created = new JWTCreatedEvent([], $before);
        $this->listener->onJwtCreated($created);
        $after = new UserBuilder()->withId('user-id')->withPassword('nouveau-hash')->build();

        // Then
        $this->expectException(InvalidTokenException::class);

        // When
        $this->listener->onJwtAuthenticated($this->authenticated($created->getData(), $after));
    }

    public function testATokenWithoutFingerprintIsRefused(): void
    {
        // Given : jeton émis avant la règle
        $user = new UserBuilder()->build();

        // Then
        $this->expectException(InvalidTokenException::class);

        // When
        $this->listener->onJwtAuthenticated($this->authenticated(['username' => $user->getEmail()], $user));
    }

    public function testAFingerprintMadeWithAnotherSecretIsRefused(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $created = new JWTCreatedEvent([], $user);
        new JwtPasswordFingerprintListener('un-autre-secret')->onJwtCreated($created);

        // Then
        $this->expectException(InvalidTokenException::class);

        // When
        $this->listener->onJwtAuthenticated($this->authenticated($created->getData(), $user));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function authenticated(array $payload, User $user): JWTAuthenticatedEvent
    {
        return new JWTAuthenticatedEvent($payload, new UsernamePasswordToken($user, 'api', $user->getRoles()));
    }
}
