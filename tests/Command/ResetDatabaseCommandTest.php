<?php

namespace Repas\Tests\Command;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Command\ResetDatabaseCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * `repas:reset-database` supprime la base : elle ne tourne qu'en développement et en test.
 *
 * Le répertoire du projet de ces essais est un dossier vide, sans `bin/console` : même quand l'environnement est permis, la première
 * étape (supprimer la base) échoue aussitôt sans rien toucher. Aucun de ces tests n'atteint une vraie base.
 */
class ResetDatabaseCommandTest extends TestCase
{
    private string $emptyProjectDir;

    protected function setUp(): void
    {
        $this->emptyProjectDir = sys_get_temp_dir() . '/repas-reset-database-' . bin2hex(random_bytes(8));
        mkdir($this->emptyProjectDir, 0700, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->emptyProjectDir);
    }

    #[DataProvider('forbiddenEnvironments')]
    public function testItRefusesToRunOutsideDevelopmentAndTest(string $environment): void
    {
        // Given
        $tester = $this->tester($environment);

        // When
        $status = $tester->execute([]);

        // Then : refusé avant toute étape (le dossier du projet n'est même pas lu)
        $this->assertSame(Command::FAILURE, $status);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        $this->assertStringContainsString("Réinitialisation refusée dans l'environnement « $environment »", $display);
        $this->assertStringContainsString('Rien n\'a été modifié.', $display);
        $this->assertStringNotContainsString('Supprimer la base de données', $display);
        $this->assertStringNotContainsString('Folder:', $display);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenEnvironments(): iterable
    {
        yield 'prod' => ['prod'];
        yield 'staging' => ['staging'];
        yield 'préproduction' => ['preprod'];
        yield 'casse différente' => ['DEV'];
        yield 'dev suivi d\'un suffixe' => ['dev2'];
    }

    #[DataProvider('allowedEnvironments')]
    public function testItGoesPastTheGuardInDevelopmentAndTest(string $environment): void
    {
        // Given : un projet sans `bin/console`
        $tester = $this->tester($environment);

        // When
        $status = $tester->execute([]);

        // Then : l'environnement est permis, la commande démarre par supprimer la base… et échoue sur ce dossier vide
        $this->assertSame(Command::FAILURE, $status);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        $this->assertStringNotContainsString('Réinitialisation refusée', $display);
        $this->assertStringContainsString('Supprimer la base de données', $display);
        $this->assertStringContainsString('Supprimer la base de données : Échec', $display);
        $this->assertStringNotContainsString('Créer la base de données', $display);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedEnvironments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'test' => ['test'];
    }

    private function tester(string $environment): CommandTester
    {
        $this->assertFileDoesNotExist($this->emptyProjectDir . '/bin/console');

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->emptyProjectDir);

        $command = new ResetDatabaseCommand($environment);
        $command->setApplication(new Application($kernel));

        return new CommandTester($command);
    }
}
