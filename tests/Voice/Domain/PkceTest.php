<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Model\Pkce;

class PkceTest extends TestCase
{
    /** L'exemple de la RFC 7636, annexe B. */
    private const string RFC_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const string RFC_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    public function testTheChallengeIsTheS256OfTheVerifierLikeTheRfcExample(): void
    {
        $this->assertSame(self::RFC_CHALLENGE, Pkce::challengeOf(self::RFC_VERIFIER));
        $this->assertTrue(Pkce::verifies(self::RFC_VERIFIER, self::RFC_CHALLENGE));
        $this->assertTrue(Pkce::isChallenge(self::RFC_CHALLENGE));
        $this->assertTrue(Pkce::isVerifier(self::RFC_VERIFIER));
    }

    public function testAnotherVerifierDoesNotVerify(): void
    {
        $this->assertFalse(Pkce::verifies(str_repeat('a', 43), self::RFC_CHALLENGE));
    }

    public function testThePlainMethodCannotPass(): void
    {
        // Given : en `plain`, le défi EST le vérificateur
        // Then : le vérificateur n'est jamais accepté comme défi, car son SHA-256 est comparé
        $this->assertFalse(Pkce::verifies(self::RFC_CHALLENGE, self::RFC_CHALLENGE));
    }

    #[DataProvider('verifiers')]
    public function testTheVerifierHasToBe43To128UnreservedCharacters(string $verifier, bool $valid): void
    {
        $this->assertSame($valid, Pkce::isVerifier($verifier));
        // Un vérificateur mal formé ne vérifie jamais, même si l'on a calculé son défi
        $this->assertSame($valid, Pkce::verifies($verifier, Pkce::challengeOf($verifier)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function verifiers(): iterable
    {
        yield '43 characters' => [str_repeat('a', 43), true];
        yield '128 characters' => [str_repeat('A', 128), true];
        yield 'every unreserved character' => [str_repeat('aZ09-._~', 6), true];
        yield '42 characters' => [str_repeat('a', 42), false];
        yield '129 characters' => [str_repeat('a', 129), false];
        yield 'a reserved character' => [str_repeat('a', 42) . '+', false];
        yield 'a space' => [str_repeat('a', 42) . ' ', false];
        yield 'a trailing newline' => [str_repeat('a', 43) . "\n", false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('challenges')]
    public function testTheChallengeHasToBeABase64UrlSha256(string $challenge, bool $valid): void
    {
        $this->assertSame($valid, Pkce::isChallenge($challenge));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function challenges(): iterable
    {
        yield '43 characters' => [str_repeat('a', 43), true];
        yield 'with - and _' => [str_repeat('-_', 21) . 'a', true];
        yield '42 characters' => [str_repeat('a', 42), false];
        yield '44 characters' => [str_repeat('a', 44), false];
        yield 'padded' => [str_repeat('a', 42) . '=', false];
        yield 'standard base64' => [str_repeat('a', 42) . '+', false];
        yield 'empty' => ['', false];
    }
}
