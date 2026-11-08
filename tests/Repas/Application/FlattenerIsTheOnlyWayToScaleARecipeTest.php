<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;

/**
 * Garde-fou de l'ADR 0040 décision 12 : une recette ne se met à l'échelle d'un nombre de personnes pour une liste de courses (planifier, retirer, changer
 * les personnes) que par `FlattenedRecipeRows` et `RecipeFlattener`, qui ajoutent les lignes de ses recettes utilisées à celles de la recette.
 * `Recipe::getRowForServing` ne rend que les lignes PROPRES : un cas d'usage qui l'appelle planifierait une recette composée sans les ingrédients de ses
 * recettes utilisées, sans aucune erreur. La méthode reste dans `Recipe` (les lignes propres, `RecipeServingRowsTest`), plus aucun code de `src/` ne
 * l'appelle.
 */
class FlattenerIsTheOnlyWayToScaleARecipeTest extends TestCase
{
    /** Le modèle qui la définit. */
    private const string DEFINITION = 'Repas/Domain/Model/Recipe.php';

    public function testNoCodeOfSrcCallsGetRowForServing(): void
    {
        // Given
        $callers = [];
        foreach (self::sources() as $path => $source) {
            if ($path !== self::DEFINITION && self::callsGetRowForServing($source)) {
                $callers[] = $path;
            }
        }

        // Then
        $this->assertSame([], $callers, 'Mettre une recette à l\'échelle passe par `FlattenedRecipeRows::of($recipe, $ownerId)->forServing($n)` (ADR 0040 décision 12), jamais `Recipe::getRowForServing`.');
    }

    public function testTheThreeHandlersThatPlanAMealUseTheFlattener(): void
    {
        // Then : le test ne doit pas être vide, les trois cas d'usage passent bien par le service d'aplatissement
        $sources = self::sources();
        foreach ([
            'Repas/Application/Command/PlannedMeal/PlannedMealHandler.php',
            'Repas/Application/Command/UpdateServingMeal/UpdateServingMealHandler.php',
            'Repas/Application/Command/RemoveMealFromPlan/removeMealFromPlanHandler.php',
        ] as $handler) {
            $this->assertArrayHasKey($handler, $sources);
            $this->assertStringContainsString('FlattenedRecipeRows', $sources[$handler], "$handler n'aplatit plus la recette.");
            $this->assertStringContainsString('->forServing(', $sources[$handler], "$handler ne met plus la recette à l'échelle.");
        }
    }

    public function testTheAnalyzerSeesACall(): void
    {
        // Then : ce que le test refuse
        $this->assertTrue(self::callsGetRowForServing('$rows = $recipe->getRowForServing($command->defaultServing);'));
        $this->assertTrue(self::callsGetRowForServing('$rows = $this->recipe->getRowForServing(abs($coefficient))->filter(fn() => true);'));
        $this->assertTrue(self::callsGetRowForServing("\$x = \$recipe\n    ->getRowForServing(4);"));

        // Then : ce qu'il accepte (le nom dans un commentaire ou une phrase n'est pas un appel)
        $this->assertFalse(self::callsGetRowForServing('// Recipe::getRowForServing ne rend que les lignes propres'));
        $this->assertFalse(self::callsGetRowForServing('$rows = $flat->forServing($command->defaultServing);'));
    }

    private static function callsGetRowForServing(string $source): bool
    {
        // Un commentaire (ligne ou bloc) ne compte pas
        $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m', '#^\s*\*.*$#m'], '', $source) ?? $source;

        return preg_match('/->\s*getRowForServing\s*\(/', $code) === 1;
    }

    /**
     * @return array<string, string> le code de chaque classe de `src/`, par chemin depuis `src/`
     */
    private static function sources(): array
    {
        $root = dirname(__DIR__, 3) . '/src/';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($root))] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }
}
