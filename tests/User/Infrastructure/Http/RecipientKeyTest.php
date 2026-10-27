<?php

namespace Repas\Tests\User\Infrastructure\Http;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\User\Infrastructure\Http\RecipientKey;

/**
 * La clé de l'adresse saisie pour le plafond par destinataire : la forme de la recherche du compte, sans l'étiquette `+x` de la partie
 * locale.
 */
class RecipientKeyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'sans étiquette' => ['alexiane@example.com', 'alexiane@example.com'];
        yield 'une étiquette' => ['alexiane+courses@example.com', 'alexiane@example.com'];
        yield 'plusieurs étiquettes' => ['alexiane+a+b@example.com', 'alexiane@example.com'];
        yield 'étiquette vide' => ['alexiane+@example.com', 'alexiane@example.com'];
        yield 'casse et espaces' => ['  Alexiane+Courses@Example.COM ', 'alexiane@example.com'];
        yield 'un plus dans le domaine reste' => ['alexiane@exam+ple.com', 'alexiane@exam+ple.com'];
        yield 'partie locale faite de l\'étiquette' => ['+courses@example.com', '+courses@example.com'];
        yield 'sans arobase' => ['alexiane+courses', 'alexiane+courses'];
        yield 'octets invalides en UTF-8' => ["Alexiane+\xFF@Example.com", "alexiane@example.com"];
        yield 'accents' => ['Éloïse+x@Example.com', 'éloïse@example.com'];
    }

    #[DataProvider('addresses')]
    public function testTheKeyIgnoresTheTagOfTheLocalPartOnly(string $typed, string $expected): void
    {
        // When / Then
        $this->assertSame($expected, RecipientKey::of($typed));
    }

    public function testTheTagAndTheCaseDoNotChangeTheKey(): void
    {
        // When / Then
        $this->assertSame(RecipientKey::of('alexiane@example.com'), RecipientKey::of(' ALEXIANE+Courses@example.com'));
        $this->assertNotSame(RecipientKey::of('alexiane@example.com'), RecipientKey::of('alexiane2@example.com'));
        $this->assertNotSame(RecipientKey::of('alexiane@example.com'), RecipientKey::of('alexiane@example.org'));
    }
}
