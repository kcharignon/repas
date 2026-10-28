<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;

/**
 * Garde-fou de l'ADR 0030 : le nom d'une recette ou d'un ingrédient commence par une majuscule, et la règle vit dans les gestionnaires de
 * commande (`StringTool::capitalizeFirst`, silencieuse : aucune erreur, le nom enregistré est le nom corrigé). Rien dans le langage
 * n'oblige à y penser : ce test oblige à le DIRE.
 *
 * Il parcourt `src/{Contexte}/Application` et échoue dès qu'une classe écrit le nom d'une recette ou d'un ingrédient sans passer par la
 * règle :
 *
 * - `Recipe::create(`, `Ingredient::create(`, `new Ingredient(`, `$recipe->update(`, `$ingredient->update(` et `->setName(` sur une recette,
 *   un ingrédient ou une copie : le texte des arguments de l'appel contient `StringTool::capitalizeFirst(` ;
 * - `Recipe::copyFromOriginal(` et `Ingredient::copyFromOriginal(` (une copie reprend le nom de l'original) : autant d'appels
 *   `->setName(StringTool::capitalizeFirst(` dans la classe que de copies.
 *
 * Une exemption s'écrit dans `$exempt`, avec son motif. Un second test vérifie que les méthodes publiques des deux modèles qui reçoivent un
 * `$name` sont celles que ce test connaît : en ajouter une impose d'y penser ici.
 */
class NamesStartWithACapitalTest extends TestCase
{
    private const string RULE = 'StringTool::capitalizeFirst(';

    /** Les écritures d'un nom : l'appel porte lui-même la règle. */
    private const array NAME_WRITERS = [
        '/\bRecipe::create\(/',
        '/\bIngredient::create\(/',
        '/\bnew Ingredient\(/',
        '/\$(?:recipe|ingredient|copy|\w+Recipe|\w+Ingredient|\w+Copy)->(?:update|setName)\(/',
    ];

    /** Les copies : le nom vient de l'original, la règle est appliquée par un `setName` juste après. */
    private const array COPIES = [
        '/\bRecipe::copyFromOriginal\(/',
        '/\bIngredient::copyFromOriginal\(/',
    ];

    /**
     * Classes de `Application` exemptées, avec le motif (chemin depuis `src/` => motif). Aucune aujourd'hui.
     *
     * @var array<string, string>
     */
    private static array $exempt = [];

    public function testEveryApplicationClassThatWritesTheNameOfARecipeOrOfAnIngredientAppliesTheRule(): void
    {
        // Given
        $writers = [];
        foreach (self::applicationFiles() as $path => $source) {
            $violations = self::violationsIn($source);
            if (self::mentionsAWriter($source)) {
                $writers[] = $path;
            }
            if (isset(self::$exempt[$path])) {
                continue;
            }

            // Then
            $this->assertSame([], $violations, sprintf("%s : le nom d'une recette ou d'un ingrédient s'écrit avec `%s…)` (ADR 0030).", $path, self::RULE));
        }

        // Then : le test ne doit pas être vide, les écritures existent bien dans l'application
        foreach (['CreateRecipeHandler', 'UpdateRecipeHandler', 'CreateIngredientHandler', 'UpdateIngredientHandler', 'CopyRecipeHandler'] as $handler) {
            $this->assertNotEmpty(array_filter($writers, static fn(string $path): bool => str_ends_with($path, "/$handler.php")), "$handler n'est plus vu comme une écriture de nom : le garde-fou ne vérifie plus rien.");
        }
    }

    public function testNoExemptionIsStale(): void
    {
        // Given : chaque exemption nomme une classe qui existe, avec son motif, et qui enfreint encore la règle
        $files = self::applicationFiles();
        $stale = [];
        foreach (self::$exempt as $path => $reason) {
            if (!isset($files[$path]) || trim($reason) === '' || self::violationsIn($files[$path]) === []) {
                $stale[] = $path;
            }
        }

        // Then
        $this->assertSame([], $stale, 'Exemptions périmées (classe disparue, motif vide ou règle désormais appliquée) : les retirer.');
    }

    public function testTheAnalyzerSeesAWriterThatSkipsTheRule(): void
    {
        // Then : ce que le test refuse
        $this->assertNotSame([], self::violationsIn('$r = Recipe::create(id: $i, name: $command->name, servings: 4);'));
        $this->assertNotSame([], self::violationsIn('$i = Ingredient::create(name: $command->name, image: "", creatorId: $id);'));
        $this->assertNotSame([], self::violationsIn('$ingredient->update(name: $command->name, image: "");'));
        $this->assertNotSame([], self::violationsIn('$recipe->update(name: $command->name, type: $t, serving: 4);'));
        $this->assertNotSame([], self::violationsIn('$copy->setName($name);'));
        $this->assertNotSame([], self::violationsIn('$x = new Ingredient($slug, $name, $image);'));
        $this->assertNotSame([], self::violationsIn('$c = Recipe::copyFromOriginal($id, $original, $author, $retained, $newRow);'));
        $this->assertNotSame([], self::violationsIn('$c = Ingredient::copyFromOriginal($source, $author, $slug); $this->save($c);'));
        // une copie dont un seul des deux noms est corrigé
        $this->assertNotSame([], self::violationsIn('$a = Recipe::copyFromOriginal($i); $a->setName(StringTool::capitalizeFirst($a->getName())); $b = Ingredient::copyFromOriginal($s);'));

        // Then : ce qu'il accepte
        $this->assertSame([], self::violationsIn('$r = Recipe::create(id: $i, name: StringTool::capitalizeFirst($command->name), servings: 4);'));
        $this->assertSame([], self::violationsIn('$i = Ingredient::create(name: StringTool::capitalizeFirst($command->name), image: "", creatorId: $id);'));
        $this->assertSame([], self::violationsIn('$ingredient->update(name: StringTool::capitalizeFirst($command->name), image: "");'));
        $this->assertSame([], self::violationsIn('$c = Recipe::copyFromOriginal($i); $c->setName(StringTool::capitalizeFirst($c->getName()));'));
        // ce qui n'est pas une écriture de nom d'une recette ou d'un ingrédient
        $this->assertSame([], self::violationsIn('$conversion->update(startUnit: $s, endUnit: $e); $shoppingList->setName($n); $x = RecipeRow::copyFromOriginal($r); $y = ShoppingListIngredient::create($i);'));
    }

    public function testTheModelsOnlyAcceptANameThroughTheMethodsThisTestKnows(): void
    {
        // Then : une nouvelle méthode publique qui reçoit un `$name` est une nouvelle porte d'entrée pour le nom : l'ajouter ici (et au test ci-dessus)
        $this->assertSame(['create', 'setName', 'update'], self::methodsAcceptingAName(Recipe::class));
        $this->assertSame(['__construct', 'create', 'setName', 'update'], self::methodsAcceptingAName(Ingredient::class));
    }

    /**
     * @return list<string> les violations (une description par appel qui n'applique pas la règle)
     */
    private static function violationsIn(string $source): array
    {
        $violations = [];
        foreach (self::NAME_WRITERS as $pattern) {
            foreach (self::callsMatching($source, $pattern) as $arguments) {
                if (!str_contains($arguments, self::RULE)) {
                    $violations[] = trim($pattern, '/') . ' ' . $arguments;
                }
            }
        }

        $copies = 0;
        foreach (self::COPIES as $pattern) {
            $copies += count(self::callsMatching($source, $pattern));
        }
        $corrections = substr_count($source, '->setName(' . self::RULE);
        if ($corrections < $copies) {
            $violations[] = sprintf('%d copie(s) reprennent le nom de leur original, %d seulement sont corrigées par `->setName(%s…)`', $copies, $corrections, self::RULE);
        }

        return $violations;
    }

    private static function mentionsAWriter(string $source): bool
    {
        foreach ([...self::NAME_WRITERS, ...self::COPIES] as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le texte des arguments de chaque appel qui correspond à `$pattern` (le motif finit par la parenthèse ouvrante).
     *
     * @return list<string>
     */
    private static function callsMatching(string $source, string $pattern): array
    {
        $calls = [];
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }
        $length = strlen($source);
        foreach ($matches[0] as [$text, $offset]) {
            $position = $offset + strlen($text);
            $begin = $position;
            $depth = 1;
            while ($position < $length && $depth > 0) {
                $depth += match ($source[$position]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                $position++;
            }
            $calls[] = rtrim(substr($source, $begin, $position - $begin - 1));
        }

        return $calls;
    }

    /**
     * @return array<string, string> le code de chaque classe de `src/{Contexte}/Application`, par chemin depuis `src/`
     */
    private static function applicationFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/src/';
        $files = [];
        foreach (glob($root . '*/Application') ?: [] as $applicationDirectory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($applicationDirectory, \FilesystemIterator::SKIP_DOTS));
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[substr($file->getPathname(), strlen($root))] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @param class-string $class
     * @return list<string> les méthodes publiques de la classe qui ont un paramètre `$name`
     */
    private static function methodsAcceptingAName(string $class): array
    {
        $methods = [];
        foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getName() === 'name') {
                    $methods[] = $method->getName();
                }
            }
        }
        sort($methods);

        return $methods;
    }
}
