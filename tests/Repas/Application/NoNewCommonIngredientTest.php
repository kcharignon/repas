<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;

/**
 * Garde-fou de l'amendement du 2026-10-20 à l'ADR 0028 (ING-012) : plus aucun NOUVEL ingrédient « commun » (sans créateur). Les anciens
 * communs existent encore jusqu'à la passation (ING-009) ; ce test ne parle que de ce que le code d'application CRÉE.
 *
 * Il parcourt `src/{Contexte}/Application` et échoue dès qu'un appel à `Ingredient::create(` ne donne pas, au paramètre `creatorId`, une
 * simple variable ou propriété (jamais `null`, `''`, ni une expression qui peut en valoir un). Les fixtures et les tests de domaine ne sont pas
 * parcourus : ils construisent des ingrédients exprès. `Ingredient::create()` exige un créateur (`string`, ING-014) ; `NoSharedIngredientTest` le garde.
 */
class NoNewCommonIngredientTest extends TestCase
{
    private const string CALL = 'Ingredient::create(';

    public function testNoApplicationCodeCreatesAnIngredientWithoutCreator(): void
    {
        // Given
        $calls = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/*/Application') ?: [] as $applicationDirectory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($applicationDirectory, \FilesystemIterator::SKIP_DOTS));
            /** @var \SplFileInfo $file */
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                foreach (self::callsIn((string) file_get_contents($file->getPathname())) as $call) {
                    $calls[$file->getPathname()][] = $call;
                }
            }
        }

        // Then : le test ne doit pas être vide, la création d'un ingrédient existe bien dans l'application
        $this->assertNotSame([], $calls, 'Aucun appel à Ingredient::create() trouvé : le garde-fou ne vérifie plus rien.');
        foreach ($calls as $path => $fileCalls) {
            foreach ($fileCalls as $arguments) {
                $this->assertMatchesRegularExpression(
                    '/\bcreatorId:\s*\$[A-Za-z_][A-Za-z0-9_]*(->[A-Za-z_][A-Za-z0-9_]*)*\s*,?\s*$/',
                    $arguments,
                    sprintf("%s : Ingredient::create() doit recevoir un créateur (`creatorId: \$variable`), jamais null ni une expression qui peut l'être.", $path),
                );
            }
        }
    }

    public function testTheAnalyzerSeesACommonBeingCreated(): void
    {
        // Given : ce que le test refuse
        $source = '$x = Ingredient::create(name: $n, image: "", department: $d, defaultCookingUnit: $c, defaultPurchaseUnit: $p, creatorId: $isAdmin ? null : $id);';

        // Then
        $this->assertCount(1, self::callsIn($source));
        $this->assertDoesNotMatchRegularExpression('/\bcreatorId:\s*\$[A-Za-z_][A-Za-z0-9_]*(->[A-Za-z_][A-Za-z0-9_]*)*\s*,?\s*$/', self::callsIn($source)[0]);
        $this->assertDoesNotMatchRegularExpression('/\bcreatorId:\s*\$[A-Za-z_][A-Za-z0-9_]*(->[A-Za-z_][A-Za-z0-9_]*)*\s*,?\s*$/', self::callsIn('Ingredient::create($n, "", $d, $c, $p, null)')[0]);
    }

    public function testCreatingAnIngredientDoesNotDependOnTheAdministratorRole(): void
    {
        // Then : le contexte `Repas` n'a plus besoin de savoir qu'un utilisateur est administrateur pour créer un ingrédient
        $properties = array_map(static fn(\ReflectionProperty $property): string => $property->getName(), new \ReflectionClass(CreateIngredientCommand::class)->getProperties());
        $this->assertNotContains('isAdmin', $properties);
    }

    /**
     * Le texte des arguments de chaque appel `Ingredient::create(…)` de la source.
     *
     * @return list<string>
     */
    private static function callsIn(string $source): array
    {
        $calls = [];
        $offset = 0;
        while (($start = strpos($source, self::CALL, $offset)) !== false) {
            $position = $start + strlen(self::CALL);
            $depth = 1;
            $length = strlen($source);
            $begin = $position;
            while ($position < $length && $depth > 0) {
                $depth += match ($source[$position]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                $position++;
            }
            $calls[] = rtrim(substr($source, $begin, $position - $begin - 1));
            $offset = $position;
        }

        return $calls;
    }
}
