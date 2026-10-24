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
 * Exception, listée ci-dessous avec sa raison : les outils d'exploitation de la passation des anciens communs (`hand-over-catalog`), de la
 * sonde de santé de l'amorçage et le « ou vide » du catalogue, qui raisonnent par nature sur la base d'AVANT la passation. Le test échoue
 * aussi quand un de ces fichiers n'a plus besoin de son exception : la liste ne fait que rétrécir.
 */
class NoSharedIngredientTest extends TestCase
{
    /**
     * @var array<string, string> fichier (relatif à la racine) => pourquoi il parle encore d'un ingrédient sans créateur
     */
    private const array OPERATIONS_OF_THE_HANDOVER = [
        'src/Repas/Infrastructure/Visibility/IngredientVisibility.php' => 'le « ou vide » du catalogue (isCommonSql), tant que la passation est rejouable',
        'src/Repas/Infrastructure/Repository/IngredientCatalogHandoverPostgreSQLRepository.php' => 'la passation écrit le créateur des anciens communs',
        'src/Repas/Infrastructure/Adoption/CatalogHandoverReader.php' => 'les relevés avant/après de la passation',
        'src/Repas/Infrastructure/Loader/IngredientSeedingReportPostgreSQLLoader.php' => 'la sonde de santé compte les ingrédients restés sans créateur',
        'src/Repas/Infrastructure/Adoption/AdoptionSnapshotReader.php' => 'l\'empreinte « surveille » les anciens communs tant que la passation est rejouable',
    ];

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
            $relative = substr($path, strlen($root) + 1);
            if (isset(self::OPERATIONS_OF_THE_HANDOVER[$relative])) {
                continue;
            }
            $violations = self::violationsIn((string) file_get_contents($path));
            if ($violations !== []) {
                $offenders[$relative] = $violations;
            }
        }

        // Then : le test parcourt bien du code, et aucun fichier ne traite un ingrédient sans créateur à part
        $this->assertGreaterThan(500, $seen, 'Le garde-fou ne parcourt plus le code : il ne vérifie plus rien.');
        $this->assertSame([], $offenders, "Un ingrédient est toujours à quelqu'un : plus de cas « sans créateur » (ING-014).");
    }

    public function testEveryExceptionIsStillNeeded(): void
    {
        // Then : une exception dont le fichier n'a plus rien d'interdit est une exception à retirer de la liste
        $root = dirname(__DIR__, 3);
        foreach (self::OPERATIONS_OF_THE_HANDOVER as $relative => $reason) {
            $this->assertFileExists($root . '/' . $relative, "L'exception « $reason » désigne un fichier qui n'existe plus : la retirer de la liste.");
            $this->assertNotSame([], self::violationsIn((string) file_get_contents($root . '/' . $relative)), "$relative n'a plus rien d'interdit : retirer son exception.");
        }
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
