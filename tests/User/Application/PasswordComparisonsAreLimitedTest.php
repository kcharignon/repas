<?php

namespace Repas\Tests\User\Application;


use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Tout cas d'usage qui compare un mot de passe saisi à celui du compte passe par le plafond des mots de passe refusés
 * (`PasswordAttemptLimiter`) : une comparaison faite ailleurs que dans `User::passwordMatch` et la connexion (qui a le sien) fait échouer
 * le test. Le modèle `User` est la seule classe à comparer.
 */
class PasswordComparisonsAreLimitedTest extends TestCase
{
    public function testEveryApplicationClassThatComparesAPasswordUsesTheLimiter(): void
    {
        // Given
        $files = new Finder()->files()->in(dirname(__DIR__, 3) . '/src/*/Application')->name('*.php');

        // When
        $unlimited = [];
        foreach ($files as $file) {
            $source = $file->getContents();
            $compares = preg_match('/passwordMatch\(|password_verify\(|isPasswordValid\(|->verify\(/', $source) === 1;
            if ($compares && !str_contains($source, 'PasswordAttemptLimiter')) {
                $unlimited[] = $file->getRelativePathname();
            }
        }

        // Then
        $this->assertSame([], $unlimited, 'Comparaison de mot de passe sans PasswordAttemptLimiter : ' . implode(', ', $unlimited));
    }

    public function testNoClassOutsideTheUserModelComparesAPasswordInTheDomainOrTheInfrastructure(): void
    {
        // Given
        $files = new Finder()->files()->in(dirname(__DIR__, 3) . '/src')->name('*.php')->notPath(['/Application\//', '#User/Domain/Model/User\.php$#']);

        // When
        $comparing = [];
        foreach ($files as $file) {
            if (preg_match('/passwordMatch\(|password_verify\(|isPasswordValid\(/', $file->getContents()) === 1) {
                $comparing[] = $file->getRelativePathname();
            }
        }

        // Then
        $this->assertSame([], $comparing, 'Comparaison de mot de passe hors du modèle User : ' . implode(', ', $comparing));
    }
}
