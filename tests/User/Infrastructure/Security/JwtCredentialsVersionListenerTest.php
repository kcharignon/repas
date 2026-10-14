<?php

namespace Repas\Tests\User\Infrastructure\Security;


use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Security\JwtCredentialsVersionListener;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class JwtCredentialsVersionListenerTest extends TestCase
{
    private JwtCredentialsVersionListener $listener;

    protected function setUp(): void
    {
        $this->listener = new JwtCredentialsVersionListener('secret-de-test');
    }

    public function testTheTokenCarriesAnOpaqueFingerprint(): void
    {
        // Given
        $user = new UserBuilder()->withPassword('hash-du-mot-de-passe')->build();
        $event = new JWTCreatedEvent(['username' => $user->getEmail()], $user);

        // When
        $this->listener->onJwtCreated($event);

        // Then : ni le hash ni la version ne sont lisibles dans le jeton
        $payload = $event->getData();
        $this->assertSame($user->getEmail(), $payload['username']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $payload['cv']);
        $this->assertStringNotContainsString('hash-du-mot-de-passe', json_encode($payload));
    }

    public function testATokenIssuedForTheCurrentCredentialsIsAccepted(): void
    {
        // Given
        $user = new UserBuilder()->build();
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
        $user = new UserBuilder()->withId('user-id')->build();
        $created = new JWTCreatedEvent([], $user);
        $this->listener->onJwtCreated($created);
        $user->changePassword('nouveau-hash');

        // Then
        $this->expectException(InvalidTokenException::class);

        // When
        $this->listener->onJwtAuthenticated($this->authenticated($created->getData(), $user));
    }

    public function testARehashOfTheSamePasswordDoesNotRevokeTheToken(): void
    {
        // Given : Symfony émet le jeton, puis recalcule le hash (nouvel algorithme ou nouveau coût) et l'enregistre
        $user = new UserBuilder()->withId('user-id')->withPassword('hash-ancien')->build();
        $created = new JWTCreatedEvent([], $user);
        $this->listener->onJwtCreated($created);
        $user->setPassword('hash-recalcule');

        // When
        $this->listener->onJwtAuthenticated($this->authenticated($created->getData(), $user));

        // Then : le jeton qu'on vient de donner reste valable
        $this->addToAssertionCount(1);
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
        new JwtCredentialsVersionListener('un-autre-secret')->onJwtCreated($created);

        // Then
        $this->expectException(InvalidTokenException::class);

        // When
        $this->listener->onJwtAuthenticated($this->authenticated($created->getData(), $user));
    }

    public function testATokenOfAnotherUserIsRefused(): void
    {
        // Given : deux utilisateurs à la même version des identifiants
        $first = new UserBuilder()->withId('first')->build();
        $second = new UserBuilder()->withId('second')->build();
        $created = new JWTCreatedEvent([], $first);
        $this->listener->onJwtCreated($created);

        // Then
        $this->expectException(InvalidTokenException::class);

        // When
        $this->listener->onJwtAuthenticated($this->authenticated($created->getData(), $second));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function authenticated(array $payload, User $user): JWTAuthenticatedEvent
    {
        return new JWTAuthenticatedEvent($payload, new UsernamePasswordToken($user, 'api', $user->getRoles()));
    }
}
