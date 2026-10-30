<?php

namespace Repas\Tests\Drive\Domain;


use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Garde-fou de DRV-701 (« ne pas durcir Leclerc », ADR 0027) : dans `src/`, une enseigne n'est nommée qu'à un seul endroit,
 * `Retailer` (la liste des identifiants et le profil de chacune) et `RetailerProfile`. Tout autre code reçoit l'enseigne en
 * paramètre, ou la demande à `Retailer::default()`, et ne connaît de son site que ce que dit son profil.
 *
 * Deux marques par enseigne le trahissent : son cas (`Retailer::LECLERC`, `Retailer::AUCHAN`) et le domaine de son site (DRV-716 : chaque enseigne n'est nommée que dans `Retailer.php` et `RetailerProfile.php`).
 */
class RetailersAreNamedInOnePlaceTest extends TestCase
{
    private const array ALLOWED_FILES = ['Drive/Domain/Model/Retailer.php', 'Drive/Domain/Model/RetailerProfile.php'];

    public function testNoCodeOfSrcNamesARetailerOutsideTheRegistry(): void
    {
        // Given
        $root = dirname(__DIR__, 3) . '/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $scanned = 0;
        $offenders = [];

        // When
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || in_array($relative, self::ALLOWED_FILES, true)) {
                continue;
            }
            ++$scanned;
            foreach (self::namings((string) file_get_contents($file->getPathname())) as $naming) {
                $offenders[] = sprintf('src/%s : %s', $relative, $naming);
            }
        }

        // Then
        $this->assertGreaterThan(100, $scanned, 'Le garde-fou ne parcourt plus src/ : il ne vérifie plus rien.');
        $this->assertSame([], $offenders, "Une enseigne est nommée hors de Retailer.php et RetailerProfile.php : passe par `Retailer` (paramètre, `Retailer::default()` ou `profile()`).");
    }

    public function testTheAnalyzerSeesTheMarks(): void
    {
        // Then
        $this->assertSame(['Retailer::LECLERC'], self::namings('$x = Retailer::LECLERC;'));
        $this->assertSame(['leclercdrive'], self::namings("\$url = 'https://a.leclercdrive.fr/';"));
        $this->assertSame(['leclercdrive'], self::namings('// LeclercDrive'));
        $this->assertSame(['Retailer::AUCHAN'], self::namings('$x = Retailer::AUCHAN;'));
        $this->assertSame(['auchan.fr'], self::namings("\$url = 'https://www.auchan.fr/';"));
        $this->assertSame([], self::namings('$retailer = Retailer::default(); $name = "leclerc";'));
    }

    /**
     * @return list<string>
     */
    private static function namings(string $source): array
    {
        $found = [];
        foreach (['Retailer::LECLERC', 'leclercdrive', 'Retailer::AUCHAN', 'auchan.fr'] as $mark) {
            if (stripos($source, $mark) !== false) {
                $found[] = $mark;
            }
        }

        return $found;
    }
}
