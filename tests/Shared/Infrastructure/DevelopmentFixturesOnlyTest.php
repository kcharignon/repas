<?php

namespace Repas\Tests\Shared\Infrastructure;


use Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\User\Infrastructure\DataFixture\UserFixture;
use ReflectionClass;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Les fixtures (données de développement) n'existent qu'en développement et en test : leur bundle est une dépendance de développement,
 * chargé dans ces deux environnements seulement ; leurs classes ne sont des services que dans ces deux environnements et ne sont pas
 * copiées dans l'image de production ; aucune n'appartient à un groupe `prod` ; le mot de passe de leur compte administrateur n'est
 * défini que par `.env.dev` et `.env.test`.
 */
class DevelopmentFixturesOnlyTest extends TestCase
{
    private const string BUNDLE_PACKAGE = 'doctrine/doctrine-fixtures-bundle';
    private const array FIXTURE_PACKAGES = ['doctrine/doctrine-fixtures-bundle', 'doctrine/data-fixtures'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $file): array
    {
        return json_decode((string) file_get_contents(self::root() . '/' . $file), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testTheFixturesPackagesAreDevelopmentDependencies(): void
    {
        // Given
        $composer = self::json('composer.json');
        $lock = self::json('composer.lock');

        // Then : déclarés en `require-dev`, jamais en `require`
        $this->assertArrayHasKey(self::BUNDLE_PACKAGE, $composer['require-dev']);
        $this->assertArrayNotHasKey(self::BUNDLE_PACKAGE, $composer['require']);

        // …et verrouillés dans `packages-dev` : `composer install --no-dev` ne les installe pas
        $production = array_column($lock['packages'], 'name');
        $development = array_column($lock['packages-dev'], 'name');
        foreach (self::FIXTURE_PACKAGES as $package) {
            $this->assertContains($package, $development, $package);
            $this->assertNotContains($package, $production, $package);
        }
    }

    public function testTheFixturesBundleIsLoadedInDevelopmentAndTestOnly(): void
    {
        // When
        $bundles = require self::root() . '/config/bundles.php';

        // Then
        $this->assertSame(['dev' => true, 'test' => true], $bundles[DoctrineFixturesBundle::class]);
    }

    public function testTheFixturesAreServicesInDevelopmentAndTestOnly(): void
    {
        // When
        $services = Yaml::parseFile(self::root() . '/config/services.yaml');

        // Then : exclues du registre commun…
        $this->assertContains('../src/*/Infrastructure/DataFixture/', $services['services']['Repas\\']['exclude']);

        // …et enregistrées par les blocs `when@dev` et `when@test`, qui sont les seuls à les connaître
        foreach (['when@dev', 'when@test'] as $block) {
            $this->assertSame(
                ['Repas\\Repas\\Infrastructure\\DataFixture\\', 'Repas\\User\\Infrastructure\\DataFixture\\'],
                array_values(array_filter(array_keys($services[$block]['services']), static fn (string $id): bool => str_ends_with($id, 'DataFixture\\'))),
                $block,
            );
        }
    }

    public function testTheFixturesConfigurationIsDevelopmentAndTestOnly(): void
    {
        // When
        $config = Yaml::parseFile(self::root() . '/config/packages/doctrine_fixtures.yaml');

        // Then : sans bloc `when@…`, le bundle absent de la production ferait échouer la compilation du conteneur
        $this->assertSame(['when@dev', 'when@test'], array_keys($config));
    }

    public function testTheProductionImageDoesNotCarryTheFixtures(): void
    {
        // When
        $ignored = file(self::root() . '/.dockerignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // Then
        $this->assertContains('src/*/Infrastructure/DataFixture/', $ignored);
        $this->assertContains('.env.dev', $ignored);
        $this->assertContains('.env.test', $ignored);
    }

    public function testNoFixtureBelongsToAProductionGroup(): void
    {
        // When
        $groups = [];
        foreach (self::fixtureClasses() as $class) {
            if (is_a($class, FixtureGroupInterface::class, true)) {
                $groups[$class] = $class::getGroups();
            }
        }

        // Then : le test voit bien les fixtures, et aucune ne se charge hors des groupes de développement, de test et de démonstration
        $this->assertGreaterThan(8, count($groups));
        foreach ($groups as $class => $names) {
            $this->assertSame([], array_values(array_diff($names, ['dev', 'test', 'demo'])), $class);
        }
    }

    public function testNothingOutsideTheFixturesDependsOnTheFixturesPackages(): void
    {
        // Given
        $root = self::root();
        $offenders = [];

        // When
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        $seen = 0;
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php' || str_contains($path, '/DataFixture/')) {
                continue;
            }
            ++$seen;
            if (preg_match('/Doctrine\\\\(Bundle\\\\FixturesBundle|Common\\\\DataFixtures)\\\\/', (string) file_get_contents($path)) === 1) {
                $offenders[] = substr($path, strlen($root) + 1);
            }
        }

        // Then : le code de production n'utilise aucune classe des paquets de fixtures
        $this->assertGreaterThan(500, $seen);
        $this->assertSame([], $offenders);
    }

    public function testTheAdministratorPasswordOfTheFixturesIsNotDefinedByTheSharedEnvironmentFile(): void
    {
        // When
        $definitions = static fn (string $file): array => preg_grep('/^\s*ADMIN_PASSWORD\s*=/', file(self::root() . '/' . $file, FILE_IGNORE_NEW_LINES));

        // Then : pas dans `.env` (lu partout, image de production comprise) ; seuls les fichiers de développement et de test la définissent
        $this->assertSame([], $definitions('.env'));
        $this->assertNotSame([], $definitions('.env.dev'));
        $this->assertNotSame([], $definitions('.env.test'));
    }

    #[DataProvider('missingPasswords')]
    public function testTheAdministratorFixtureRefusesToRunWithoutAPassword(?string $password): void
    {
        // Given
        $hasher = $this->createStub(PasswordHasherInterface::class);
        $hasher->method('hash')->willReturn('hash');
        $factory = $this->createStub(PasswordHasherFactoryInterface::class);
        $factory->method('getPasswordHasher')->willReturn($hasher);
        $fixture = new UserFixture($factory, $password);

        // Then
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ADMIN_PASSWORD');

        // When
        $fixture->load($this->createStub(ObjectManager::class));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function missingPasswords(): iterable
    {
        yield 'non défini' => [null];
        yield 'vide' => [''];
    }

    /**
     * @return list<class-string>
     */
    private static function fixtureClasses(): array
    {
        $classes = [];
        foreach (glob(self::root() . '/src/*/Infrastructure/DataFixture/*Fixture.php') as $path) {
            $class = 'Repas\\' . str_replace('/', '\\', substr($path, strlen(self::root() . '/src/'), -strlen('.php')));
            if (!(new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
