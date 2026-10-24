<?php

namespace Repas\Tests\Repas\Application;


use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use ReflectionMethod;

/**
 * Garde-fou d'ING-014 (ADR 0028, amendement du 2026-10-20) : plus d'ingrédient « commun », donc plus aucun traitement à part d'un
 * ingrédient sans créateur. Aucun fichier de `src/` (hors `Migrations` et `DataFixture`, qui portent l'histoire) ne doit écrire
 * `creator IS NULL`, `creatorId IS NULL`, `creator IS DISTINCT`, `getCreatorId() === null`, `isCommon…` ni `creatorId: null` : un
 * ingrédient est à quelqu'un, et c'est tout. Les conversions GÉNÉRALES (`conversion.ingredient` vide) sont un autre sujet et restent permises.
 *
 */
class NoSharedIngredientTest extends TestCase
{
    private const string FORBIDDEN = '/creator(?:Id)?\s+IS\s+NULL|creator\s+IS\s+DISTINCT|getCreatorId\(\)\s*===\s*null|isCommon|creatorId:\s*null/i';

    public function testNoSourceFileTreatsAnIngredientWithoutCreatorApart(): void
    {
        // Given
        $root = dirname(__DIR__, 3);
        $offenders = [];
        $seen = 0;

        // When
        foreach (self::sourceFiles($root) as $path) {
            ++$seen;
            $violations = self::violationsIn((string) file_get_contents($path));
            if ($violations !== []) {
                $offenders[substr($path, strlen($root) + 1)] = $violations;
            }
        }

        // Then : le test parcourt bien du code, et aucun fichier ne traite un ingrédient sans créateur à part
        $this->assertGreaterThan(500, $seen, 'Le garde-fou ne parcourt plus le code : il ne vérifie plus rien.');
        $this->assertSame([], $offenders, "Un ingrédient est toujours à quelqu'un : plus de cas « sans créateur » (ING-014).");
    }

    #[DataProvider('forbiddenSources')]
    public function testTheAnalyzerSeesWhatItForbids(string $source): void
    {
        // Then : réintroduire un de ces traitements dans un fichier de `src/` ferait échouer le garde-fou
        $this->assertNotSame([], self::violationsIn($source));
    }

    public static function forbiddenSources(): iterable
    {
        yield 'creator IS NULL' => ["' WHERE i.creator IS NULL'"];
        yield 'creator  is  null (casse et espaces)' => ['WHERE i.creator  is   null'];
        yield 'creatorId IS NULL' => ['$qb->where(\'i.creatorId IS NULL\')'];
        yield 'creator IS DISTINCT FROM' => ['AND o.creator IS DISTINCT FROM :u'];
        yield 'getCreatorId() === null' => ['if ($ingredient->getCreatorId() === null) {'];
        yield 'isCommon' => ['if ($ingredient->isCommon()) {'];
        yield 'isCommonSql' => ['IngredientVisibility::isCommonSql(\'i\')'];
        yield 'creatorId: null' => ['Ingredient::create(name: $n, creatorId: null)'];
    }

    public function testTheAnalyzerLeavesTheGeneralConversionsAlone(): void
    {
        // Then : une conversion générale n'a pas d'ingrédient, ce n'est pas un ingrédient sans créateur
        $this->assertSame([], self::violationsIn("'SELECT * FROM conversion WHERE ingredient IS NULL'"));
        $this->assertSame([], self::violationsIn('$conversion->getIngredient() === null'));
    }

    public function testAnIngredientCannotBeCreatedWithoutCreator(): void
    {
        // Given
        $parameter = null;
        foreach (new ReflectionMethod(Ingredient::class, 'create')->getParameters() as $candidate) {
            if ($candidate->getName() === 'creatorId') {
                $parameter = $candidate;
            }
        }

        // Then : le créateur est une chaîne, jamais nul
        $this->assertNotNull($parameter);
        $this->assertSame('string', (string) $parameter->getType());
        $this->assertFalse($parameter->getType()->allowsNull());
        $this->assertSame('string', (string) new ReflectionMethod(Ingredient::class, 'getCreatorId')->getReturnType());
    }

    public function testALoadedRowWithoutCreatorIsRefusedWithAClearMessage(): void
    {
        // Then : la lecture d'une base d'avant la passation échoue franchement, au lieu d'un comportement partiel
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/beurre.*passation/');

        // When
        new IngredientBuilder()->isButter()->withoutCreator()->build();
    }

    /**
     * @return list<string> les passages interdits trouvés dans cette source
     */
    private static function violationsIn(string $source): array
    {
        preg_match_all(self::FORBIDDEN, $source, $matches);

        return $matches[0];
    }

    /**
     * @return iterable<string> les chemins des fichiers PHP de `src/`, hors `Migrations` et `DataFixture`
     */
    private static function sourceFiles(string $root): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php' || str_contains($path, '/Migrations/') || str_contains($path, '/DataFixture/')) {
                continue;
            }

            yield $path;
        }
    }
}
