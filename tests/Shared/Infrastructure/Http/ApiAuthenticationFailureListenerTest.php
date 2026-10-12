<?php

namespace Repas\Tests\Shared\Infrastructure\Http;


use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Infrastructure\Http\ApiAuthenticationFailureListener;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class ApiAuthenticationFailureListenerTest extends TestCase
{
    public function testMissingTokenGivesAuthenticationRequired(): void
    {
        // Given
        $event = new JWTNotFoundEvent(new AuthenticationException('JWT Token not found'), null);

        // When
        (new ApiAuthenticationFailureListener())->onJwtNotFound($event);

        // Then
        $this->assertUnauthorized($event, 'Authentication required.');
    }

    public function testInvalidTokenGivesInvalidToken(): void
    {
        // Given
        $event = new JWTInvalidEvent(new AuthenticationException('Invalid JWT Token'), null);

        // When
        (new ApiAuthenticationFailureListener())->onJwtInvalid($event);

        // Then
        $this->assertUnauthorized($event, 'Invalid token.');
    }

    public function testExpiredTokenGivesExpiredToken(): void
    {
        // Given
        $event = new JWTExpiredEvent(new AuthenticationException('Expired JWT Token'), null);

        // When
        (new ApiAuthenticationFailureListener())->onJwtExpired($event);

        // Then
        $this->assertUnauthorized($event, 'Expired token.');
    }

    public function testLoginFailureDoesNotLeakTheCause(): void
    {
        // Given
        $event = new AuthenticationFailureEvent(new AuthenticationException('Votre compte est désactivé.'), null);

        // When
        (new ApiAuthenticationFailureListener())->onAuthenticationFailure($event);

        // Then
        $this->assertUnauthorized($event, 'Invalid credentials.');
    }

    private function assertUnauthorized(AuthenticationFailureEvent $event, string $expectedMessage): void
    {
        $response = $event->getResponse();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
        $this->assertSame(
            ['error' => ['message' => $expectedMessage]],
            json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)
        );
    }
}
