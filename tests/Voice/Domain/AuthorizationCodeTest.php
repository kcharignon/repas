<?php

namespace Repas\Tests\Voice\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Voice\Domain\Model\AuthorizationCode;

class AuthorizationCodeTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-25 12:00:00');
    }

    public function testACodeIsValidTenMinutesAndNotUsed(): void
    {
        // Given
        $code = $this->code();

        // Then : jamais plus de 10 minutes, à la seconde près
        $this->assertSame('2026-10-25 12:10:00', $code->getExpiresAt()->format('Y-m-d H:i:s'));
        $this->assertFalse($code->isExpired($this->now->modify('+599 seconds')));
        $this->assertTrue($code->isExpired($this->now->modify('+600 seconds')));
        $this->assertFalse($code->isUsed());
        $this->assertNull($code->getLinkId());
        $this->assertSame('code-hash', $code->getId());
    }

    public function testItBelongsToTheClientItWasIssuedTo(): void
    {
        $this->assertTrue($this->code()->belongsToClient(AlexaTestClient::CLIENT_ID));
        $this->assertFalse($this->code()->belongsToClient('another-client'));
    }

    public function testItAcceptsTheSameRedirectUriAndTheVerifierOfItsChallenge(): void
    {
        // Given
        $pkce = AlexaTestClient::pkce();
        $code = $this->code($pkce['challenge']);

        // Then
        $this->assertTrue($code->accepts(AlexaTestClient::REDIRECT_URI, $pkce['verifier']));
    }

    public function testItRefusesAnotherVerifier(): void
    {
        $code = $this->code(AlexaTestClient::pkce()['challenge']);

        $this->assertFalse($code->accepts(AlexaTestClient::REDIRECT_URI, AlexaTestClient::pkce('b')['verifier']));
    }

    public function testItRefusesAnotherRedirectUriEvenOneAuthorizedForAnotherCode(): void
    {
        // Given : l'autre adresse autorisée, un préfixe, une casse, une barre finale
        $pkce = AlexaTestClient::pkce();
        $code = $this->code($pkce['challenge']);

        // Then : comparaison EXACTE
        foreach ([AlexaTestClient::OTHER_REDIRECT_URI, AlexaTestClient::REDIRECT_URI . '/', AlexaTestClient::REDIRECT_URI . '?x=1', strtoupper(AlexaTestClient::REDIRECT_URI), substr(AlexaTestClient::REDIRECT_URI, 0, -1), ''] as $other) {
            $this->assertFalse($code->accepts($other, $pkce['verifier']), $other);
        }
    }

    private function code(string $challenge = 'challenge'): AuthorizationCode
    {
        return AuthorizationCode::issue('code-hash', 'user-1', AlexaTestClient::CLIENT_ID, AlexaTestClient::REDIRECT_URI, $challenge, $this->now);
    }
}
