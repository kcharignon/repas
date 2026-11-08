<?php

namespace Repas\Tests\Repas\Infrastructure\Marmiton;


use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Repas\Repas\Domain\Interface\MarmitonPageReader;
use Repas\Repas\Infrastructure\Marmiton\MarmitonHttpPageReader;
use SplFileInfo;

/**
 * Les couches du lecteur de page Marmiton (ADR 0037 décision 13, MAR-001) : le port est dans le domaine, l'adaptateur HTTP (avec son
 * extracteur) dans `Infrastructure\Marmiton`, et RIEN d'autre ne les nomme : le reste du code ne parle qu'au port. Le résolveur de noms et le contrôle des
 * adresses publiques sont partagés avec le téléchargement d'images (ADR 0039) dans `Infrastructure\Network` : seuls les deux appels sortants les nomment.
 * Deptrac garde le sens des dépendances ; ce test garde l'emplacement et l'isolement.
 */
class MarmitonReaderLayersTest extends TestCase
{
    private const array INFRASTRUCTURE_CLASSES = ['MarmitonHttpPageReader', 'MarmitonRecipeExtractor'];
    /** Le résolveur de noms et le contrôle des adresses publiques servent aussi au téléchargement d'une image par lien (ADR 0039) : `Infrastructure\Network`. */
    private const array NETWORK_CLASSES = ['HostAddressResolver', 'DnsHostAddressResolver', 'PublicAddress'];

    public function testThePortIsInTheDomainAndTheAdapterInTheInfrastructure(): void
    {
        // Then
        $port = new ReflectionClass(MarmitonPageReader::class);
        $this->assertTrue($port->isInterface());
        $this->assertSame('Repas\Repas\Domain\Interface', $port->getNamespaceName());

        $adapter = new ReflectionClass(MarmitonHttpPageReader::class);
        $this->assertTrue($adapter->implementsInterface(MarmitonPageReader::class));
        $this->assertSame('Repas\Repas\Infrastructure\Marmiton', $adapter->getNamespaceName());
        $this->assertTrue($adapter->isFinal());
    }

    public function testNothingOutsideTheMarmitonInfrastructureNamesTheAdapter(): void
    {
        // Given : tout `src/` sauf `Infrastructure/Marmiton`
        $infrastructureDirectory = dirname(__DIR__, 4) . '/src/Repas/Infrastructure/Marmiton/';
        $offenders = [];
        $scanned = 0;
        foreach ($this->sourceFiles() as $file) {
            if (str_starts_with($file->getPathname(), $infrastructureDirectory)) {
                continue;
            }
            ++$scanned;

            $content = (string) file_get_contents($file->getPathname());
            foreach (self::INFRASTRUCTURE_CLASSES as $class) {
                // le nom de la classe, entier (`MarmitonHttpPageReader` n'est pas `MarmitonPageReader`)
                if (preg_match('/\b' . $class . '\b/', $content) === 1) {
                    $offenders[] = $file->getPathname() . ' nomme ' . $class;
                }
            }
        }

        // Then
        $this->assertGreaterThan(100, $scanned, 'Le parcours de src/ ne vérifie plus rien.');
        $this->assertSame([], $offenders, 'Seul le port MarmitonPageReader sort de Infrastructure\Marmiton.');
    }

    public function testTheNetworkToolsAreNamedOnlyByTheOutgoingCalls(): void
    {
        // Given : tout `src/` sauf les trois dossiers d'infrastructure qui font des appels sortants (ou les définissent)
        $base = dirname(__DIR__, 4) . '/src/Repas/Infrastructure/';
        $allowed = [$base . 'Marmiton/', $base . 'Image/', $base . 'Network/'];
        $offenders = [];
        foreach ($this->sourceFiles() as $file) {
            foreach ($allowed as $directory) {
                if (str_starts_with($file->getPathname(), $directory)) {
                    continue 2;
                }
            }

            $content = (string) file_get_contents($file->getPathname());
            foreach (self::NETWORK_CLASSES as $class) {
                if (preg_match('/\b' . $class . '\b/', $content) === 1) {
                    $offenders[] = $file->getPathname() . ' nomme ' . $class;
                }
            }
        }

        // Then
        $this->assertSame([], $offenders);
        foreach (self::NETWORK_CLASSES as $class) {
            $this->assertFileExists($base . 'Network/' . $class . '.php');
        }
    }

    public function testTheDomainOfTheReaderImportsNothingButTheDomain(): void
    {
        // Given
        $directory = dirname(__DIR__, 4) . '/src/Repas/Domain/';
        $files = [
            $directory . 'Model/MarmitonUrl.php',
            $directory . 'Model/MarmitonRecipePage.php',
            $directory . 'Interface/MarmitonPageReader.php',
            $directory . 'Exception/MarmitonImportException.php',
        ];

        foreach ($files as $path) {
            // When
            $this->assertFileExists($path);
            preg_match_all('/^use ([^;]+);$/m', (string) file_get_contents($path), $imports);

            // Then
            foreach ($imports[1] as $import) {
                $this->assertMatchesRegularExpression('/^Repas\\\\(Repas|Shared)\\\\Domain\\\\/', $import, $path . ' importe ' . $import);
            }
        }
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function sourceFiles(): iterable
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 4) . '/src', FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                yield $file;
            }
        }
    }
}
