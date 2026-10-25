<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Model\OpaqueToken;

class OpaqueTokenTest extends TestCase
{
    public function testTheHashIsTheSha256InHexadecimalAndNeverTheToken(): void
    {
        // Given
        $token = 'Zt4Lw9RkEoPq2YhXn6VbGcJdUaSf8MiH1TgNyQr0eKs';

        // When
        $hash = OpaqueToken::hash($token);

        // Then
        $this->assertSame(hash('sha256', $token), $hash);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $hash);
        $this->assertStringNotContainsString($token, $hash);
    }

    public function testAGeneratedLookingTokenIsWellFormed(): void
    {
        $this->assertTrue(OpaqueToken::isWellFormed(rtrim(strtr(base64_encode(random_bytes(OpaqueToken::BYTES)), '+/', '-_'), '=')));
        $this->assertTrue(OpaqueToken::isWellFormed(str_repeat('a', 43)));
    }

    #[DataProvider('malformed')]
    public function testAnythingElseIsNotOneOfOurTokens(string $value): void
    {
        $this->assertFalse(OpaqueToken::isWellFormed($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('a', 42)];
        yield 'too long' => [str_repeat('a', 44)];
        yield 'a JWT' => ['eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.c2lnbmF0dXJl'];
        yield 'standard base64 characters' => [str_repeat('a', 41) . '+/'];
        yield 'padding' => [str_repeat('a', 42) . '='];
        yield 'a space' => [str_repeat('a', 42) . ' '];
        yield 'a trailing newline' => [str_repeat('a', 43) . "\n"];
    }
}
