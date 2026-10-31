<?php

namespace Repas\Tests\Shared\Architecture;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou de l'ADR 0035 : les contextes ne se parlent que par un Open Host Service (producteur) et une couche anticorruption
 * (consommateur). Ce qu'on ne peut pas dire à Deptrac (nom, forme, genre lecture/écriture) est vérifié ici sur les vrais fichiers de `src/`.
 *
 *  - `Application/OpenHostService/` : `…CommandOHS` ou `…QueryOHS` (`final readonly class`), ou un `…DTO` publié ;
 *  - `Application/Port/` : des interfaces `…CommandPort` ou `…QueryPort` ;
 *  - `Infrastructure/Acl/` : `…CommandAdapter` (implémente un `…CommandPort`) ou `…QueryAdapter` (un `…QueryPort`), `final readonly class`,
 *    un adapter par port ;
 *  - un `CommandOHS` ne lit ni par un Loader ni par le QueryBus, un `QueryOHS` ni par un Repository ni par le CommandBus ;
 *  - un handler d'écriture (ou un Repository) n'injecte que des `CommandPort`, un handler de lecture (ou un Loader) que des `QueryPort`.
 */
class OpenHostServiceConventionTest extends TestCase
{
    private const string OHS = '#^src/(?:Repas|User|Drive|Voice)/Application/OpenHostService/#';
    private const string PORT = '#^src/(?:Repas|User|Drive|Voice)/Application/Port/#';
    private const string ACL = '#^src/(?:Repas|User|Drive|Voice)/Infrastructure/Acl/#';

    public function testEveryOpenHostServiceFileIsAnOhsOrAPublishedDto(): void
    {
        // When
        $offenders = [];
        foreach (SourceFiles::matching(self::OHS) as $path => $source) {
            $problem = self::ohsProblem($path, $source);
            if ($problem !== null) {
                $offenders[$path] = $problem;
            }
        }

        // Then
        $this->assertSame([], $offenders);
    }

    public function testEveryPortIsACommandPortOrAQueryPortInterface(): void
    {
        // When
        $offenders = [];
        foreach (SourceFiles::matching(self::PORT) as $path => $source) {
            $problem = self::portProblem($path, $source);
            if ($problem !== null) {
                $offenders[$path] = $problem;
            }
        }

        // Then
        $this->assertSame([], $offenders);
    }

    public function testEveryAclClassIsAnAdapterOfOnePortOfTheSameKind(): void
    {
        // When
        $offenders = [];
        foreach (SourceFiles::matching(self::ACL) as $path => $source) {
            $problem = self::adapterProblem($path, $source);
            if ($problem !== null) {
                $offenders[$path] = $problem;
            }
        }

        // Then
        $this->assertSame([], $offenders);
    }

    public function testEveryPortHasExactlyOneAdapter(): void
    {
        // Given
        $adapted = [];
        foreach (SourceFiles::matching(self::ACL) as $source) {
            if (preg_match('/implements\s+(\w+(?:Command|Query)Port)\b/', $source, $matches) === 1) {
                $adapted[$matches[1]][] = $matches[0];
            }
        }

        // When
        $offenders = [];
        foreach (SourceFiles::matching(self::PORT) as $path => $source) {
            $name = basename($path, '.php');
            $count = count($adapted[$name] ?? []);
            if ($count !== 1) {
                $offenders[$name] = $count . ' adapter(s) au lieu d\'un seul';
            }
        }

        // Then
        $this->assertSame([], $offenders);
    }

    public function testAnOpenHostServiceNeverReadsOnTheWrongSide(): void
    {
        // When
        $offenders = [];
        foreach (SourceFiles::matching(self::OHS) as $path => $source) {
            foreach (self::wrongSideImports($path, $source) as $import) {
                $offenders[$path][] = $import;
            }
        }

        // Then
        $this->assertSame([], $offenders, 'Un CommandOHS n\'utilise ni Loader ni QueryBus ; un QueryOHS ni Repository ni CommandBus (ADR 0035).');
    }

    public function testAHandlerOnlyInjectsPortsOfItsOwnSide(): void
    {
        // When
        $offenders = [];
        foreach (SourceFiles::all() as $path => $source) {
            foreach (self::wrongPortImports($path, $source) as $import) {
                $offenders[$path][] = $import;
            }
        }

        // Then
        $this->assertSame([], $offenders, 'Un handler d\'écriture n\'injecte que des CommandPort, un handler de lecture que des QueryPort (ADR 0035).');
    }

    // --- l'analyseur, prouvé sur des sources d'exemple : s'il se trompait, les tests ci-dessus ne vérifieraient plus rien ---

    public static function ohsProblem(string $path, string $source): ?string
    {
        $name = basename($path, '.php');
        $isOhs = str_ends_with($name, 'CommandOHS') || str_ends_with($name, 'QueryOHS');
        if (!$isOhs && !str_ends_with($name, 'DTO')) {
            return 'doit s\'appeler …CommandOHS, …QueryOHS ou …DTO';
        }

        return preg_match('/^final readonly class ' . preg_quote($name, '/') . '\b/m', $source) === 1 ? null : 'doit être une `final readonly class`';
    }

    public static function portProblem(string $path, string $source): ?string
    {
        $name = basename($path, '.php');
        if (!str_ends_with($name, 'CommandPort') && !str_ends_with($name, 'QueryPort')) {
            return 'doit s\'appeler …CommandPort ou …QueryPort';
        }

        return preg_match('/^interface ' . preg_quote($name, '/') . '\b/m', $source) === 1 ? null : 'doit être une interface';
    }

    public static function adapterProblem(string $path, string $source): ?string
    {
        $name = basename($path, '.php');
        foreach (['Command', 'Query'] as $kind) {
            if (!str_ends_with($name, $kind . 'Adapter')) {
                continue;
            }
            if (preg_match('/^final readonly class ' . preg_quote($name, '/') . '\b/m', $source) !== 1) {
                return 'doit être une `final readonly class`';
            }

            return preg_match('/implements\s+\w+' . $kind . 'Port\b/', $source) === 1 ? null : 'doit implémenter un …' . $kind . 'Port';
        }

        return 'doit s\'appeler …CommandAdapter ou …QueryAdapter';
    }

    /** @return list<string> */
    public static function wrongSideImports(string $path, string $source): array
    {
        $forbidden = match (true) {
            str_ends_with($path, 'CommandOHS.php') => '/(Loader|QueryBusInterface)$/',
            str_ends_with($path, 'QueryOHS.php') => '/(Repository|CommandBusInterface)$/',
            default => null,
        };
        if ($forbidden === null) {
            return [];
        }

        return array_values(array_filter(SourceFiles::imports(SourceFiles::code($source)), static fn (string $import): bool => preg_match($forbidden, $import) === 1));
    }

    /** @return list<string> */
    public static function wrongPortImports(string $path, string $source): array
    {
        $isWrite = preg_match('#^src/(?:Repas|Drive|Voice)/Application/Command/#', $path) === 1
            || preg_match('#^src/User/Application/(?!Query/|Loader/|Port/|OpenHostService/)[A-Za-z]+/[A-Za-z]+Handler\.php$#', $path) === 1
            || preg_match('#^src/[A-Za-z]+/Infrastructure/Repository/#', $path) === 1;
        $isRead = preg_match('#^src/[A-Za-z]+/Application/Query/#', $path) === 1
            || preg_match('#^src/[A-Za-z]+/Infrastructure/Loader/#', $path) === 1;
        $forbidden = match (true) {
            $isWrite => '/QueryPort$/',
            $isRead => '/CommandPort$/',
            default => null,
        };
        if ($forbidden === null) {
            return [];
        }

        return array_values(array_filter(SourceFiles::imports(SourceFiles::code($source)), static fn (string $import): bool => preg_match($forbidden, $import) === 1));
    }

    /** @return Generator<string, array{string, string, bool}> */
    public static function analyzerCases(): Generator
    {
        yield 'un OHS d\'écriture bien formé' => ['src/Repas/Application/OpenHostService/ShoppingListCommandOHS.php', "<?php\nfinal readonly class ShoppingListCommandOHS\n{\n}\n", true];
        yield 'un OHS non final' => ['src/Repas/Application/OpenHostService/ShoppingListCommandOHS.php', "<?php\nclass ShoppingListCommandOHS\n{\n}\n", false];
        yield 'une classe sans suffixe' => ['src/Repas/Application/OpenHostService/ShoppingListOHS.php', "<?php\nfinal readonly class ShoppingListOHS\n{\n}\n", false];
        yield 'un DTO publié' => ['src/Repas/Application/OpenHostService/AddedLineDTO.php', "<?php\nfinal readonly class AddedLineDTO\n{\n}\n", true];
    }

    #[DataProvider('analyzerCases')]
    public function testTheOhsAnalyzerSeesWhatItForbids(string $path, string $source, bool $valid): void
    {
        $this->assertSame($valid, self::ohsProblem($path, $source) === null);
    }

    public function testThePortAnalyzerSeesWhatItForbids(): void
    {
        $this->assertNull(self::portProblem('src/Voice/Application/Port/ActiveUserQueryPort.php', "<?php\ninterface ActiveUserQueryPort\n{\n}\n"));
        $this->assertNotNull(self::portProblem('src/Voice/Application/Port/ActiveUserQueryPort.php', "<?php\nfinal readonly class ActiveUserQueryPort\n{\n}\n"));
        $this->assertNotNull(self::portProblem('src/Voice/Application/Port/ActiveUserChecker.php', "<?php\ninterface ActiveUserChecker\n{\n}\n"));
    }

    public function testTheAdapterAnalyzerSeesWhatItForbids(): void
    {
        $good = "<?php\nfinal readonly class ActiveUserQueryAdapter implements ActiveUserQueryPort\n{\n}\n";
        $this->assertNull(self::adapterProblem('src/Voice/Infrastructure/Acl/ActiveUserQueryAdapter.php', $good));
        // Un adapter de lecture qui implémente un port d'écriture mélange les deux côtés
        $this->assertNotNull(self::adapterProblem('src/Voice/Infrastructure/Acl/ActiveUserQueryAdapter.php', str_replace('ActiveUserQueryPort', 'ActiveUserCommandPort', $good)));
        $this->assertNotNull(self::adapterProblem('src/Voice/Infrastructure/Acl/ActiveUserQueryMapper.php', "<?php\nfinal readonly class ActiveUserQueryMapper\n{\n}\n"));
    }

    public function testTheSideAnalyzersSeeWhatTheyForbid(): void
    {
        $commandOhs = "<?php\nuse Repas\\User\\Application\\Loader\\UserLoader;\nuse Repas\\Shared\\Application\\Interface\\QueryBusInterface;\nuse Repas\\User\\Domain\\Interface\\UserRepository;\n";
        $this->assertSame(
            ['Repas\\User\\Application\\Loader\\UserLoader', 'Repas\\Shared\\Application\\Interface\\QueryBusInterface'],
            self::wrongSideImports('src/User/Application/OpenHostService/UserCommandOHS.php', $commandOhs),
        );
        $queryOhs = "<?php\nuse Repas\\User\\Domain\\Interface\\UserRepository;\nuse Repas\\User\\Application\\Loader\\UserLoader;\n";
        $this->assertSame(['Repas\\User\\Domain\\Interface\\UserRepository'], self::wrongSideImports('src/User/Application/OpenHostService/UserQueryOHS.php', $queryOhs));
        // Un mot de commentaire n'est pas un import
        $this->assertSame([], self::wrongSideImports('src/User/Application/OpenHostService/UserQueryOHS.php', "<?php\n// use Repas\\User\\Domain\\Interface\\UserRepository;\n"));

        $handler = "<?php\nuse Repas\\Voice\\Application\\Port\\ActiveUserQueryPort;\n";
        $this->assertSame(['Repas\\Voice\\Application\\Port\\ActiveUserQueryPort'], self::wrongPortImports('src/Voice/Application/Command/ExchangeAuthorizationCode/ExchangeAuthorizationCodeHandler.php', $handler));
        $this->assertSame(['Repas\\Voice\\Application\\Port\\ActiveUserQueryPort'], self::wrongPortImports('src/User/Application/ChangeUserStatus/ChangeUserStatusHandler.php', $handler));
        $this->assertSame([], self::wrongPortImports('src/Voice/Application/Query/ResolveAccessGrant/ResolveAccessGrantQueryHandler.php', $handler));
        $this->assertSame(['Repas\\Voice\\Application\\Port\\ActiveUserCommandPort'], self::wrongPortImports('src/Voice/Infrastructure/Loader/VoiceLinkPostgreSQLLoader.php', "<?php\nuse Repas\\Voice\\Application\\Port\\ActiveUserCommandPort;\n"));
    }
}
