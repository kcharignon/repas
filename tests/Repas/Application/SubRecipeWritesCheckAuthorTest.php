<?php

namespace Repas\Tests\Repas\Application;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeHandler;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeHandler;
use Repas\Repas\Application\Service\RecipeComponentsWriter;
use Repas\Repas\Domain\Service\RecipeComponentRules;

/**
 * Garde-fou de l'ADR 0040 (décisions 7, 8 et 26) : une écriture qui reçoit l'identifiant d'une recette UTILISÉE doit contrôler que cette recette est
 * de l'AUTEUR de la recette écrite (et visible de qui écrit), sinon un utilisateur lirait ou lierait la recette d'un autre, ou, pire, une recette
 * secrète. Rien dans le langage n'oblige à y penser : ce test oblige à le DIRE.
 *
 * Il parcourt les commandes de `src/{Contexte}/Application/Command` et échoue dès qu'une classe porte des recettes utilisées (`components`,
 * `RecipeComponentSubCommand`…) sans figurer dans `DECLARED`, avec le cas d'usage qui les écrit. Il vérifie ensuite que chaque cas d'usage déclaré
 * passe par `RecipeComponentsWriter` (le seul endroit qui les juge) et que ce service appelle bien les règles d'auteur, de profondeur et de secret,
 * le verrou et la lecture en lot.
 *
 * La COPIE d'une recette composée (COMP-003) ne reçoit aucun identifiant : elle copie ceux de l'original, de l'auteur du copieur ou de l'auteur de
 * l'original ; elle est déclarée à part quand elle arrive.
 */
class SubRecipeWritesCheckAuthorTest extends TestCase
{
    /**
     * Les commandes qui portent des recettes utilisées reçues d'un client, avec le cas d'usage qui les écrit.
     *
     * @var array<class-string, class-string>
     */
    private const array DECLARED = [
        CreateRecipeCommand::class => CreateRecipeHandler::class,
        UpdateRecipeCommand::class => UpdateRecipeHandler::class,
    ];

    /** Ce qui, dans une commande, dit « une recette qu'une autre utilise » : le type d'une sous-commande ou le nom d'une propriété. */
    private const string CARRIER = '/\bRecipeComponentSubCommand\b|\$(?:components|componentIds?|usedRecipes?|usedRecipeIds?|subRecipes?|subRecipeIds?)\b/';

    public function testEveryCommandThatReceivesUsedRecipesIsDeclared(): void
    {
        // Given
        $carriers = self::carriersIn(dirname(__DIR__, 3) . '/src');

        // When
        $undeclared = array_values(array_diff($carriers, array_keys(self::DECLARED)));

        // Then
        $this->assertSame(
            [],
            $undeclared,
            "Ces commandes reçoivent des recettes utilisées sans être déclarées : fais passer leur cas d'usage par RecipeComponentsWriter (l'auteur, la profondeur,"
            . ' le secret, le verrou) puis ajoute-les à DECLARED.',
        );
    }

    public function testNoDeclarationIsLeftBehindOnceTheCommandNoLongerCarriesUsedRecipes(): void
    {
        // Given
        $carriers = self::carriersIn(dirname(__DIR__, 3) . '/src');

        // Then : une liste qui garde des classes disparues ne protégerait plus rien
        $this->assertSame([], array_values(array_diff(array_keys(self::DECLARED), $carriers)), 'DECLARED cite une commande qui ne porte plus de recette utilisée.');
    }

    public function testEveryDeclaredUseCaseWritesThemThroughTheWriter(): void
    {
        foreach (array_unique(array_values(self::DECLARED)) as $handler) {
            // Given
            $reflection = new ReflectionClass($handler);
            $injected = array_filter(
                $reflection->getConstructor()?->getParameters() ?? [],
                static fn(\ReflectionParameter $parameter): bool => (string) $parameter->getType() === RecipeComponentsWriter::class,
            );
            $source = (string) file_get_contents((string) $reflection->getFileName());

            // Then
            $this->assertCount(1, $injected, "$handler doit injecter RecipeComponentsWriter.");
            $this->assertMatchesRegularExpression('/\$this->' . preg_quote(array_values($injected)[0]->getName(), '/') . '->apply\(/', $source, "$handler injecte le service mais ne s'en sert pas.");
        }
    }

    public function testTheWriterChecksTheAuthorTheDepthAndTheSecretAfterTakingTheLockAndReadsInOneBatch(): void
    {
        // Given
        $source = (string) file_get_contents((string) new ReflectionClass(RecipeComponentsWriter::class)->getFileName());

        // Then : le verrou avant la lecture, la lecture en lot, puis les règles dans l'ordre de la décision 26
        $positions = [];
        foreach (['->acquire(', '->findByIds(', '->assertFound(', '->isUsedAsComponent(', '->assertDepth(', '->assertSecrecy('] as $call) {
            $position = strpos($source, $call);
            $this->assertNotFalse($position, "RecipeComponentsWriter n'appelle plus $call.");
            $positions[$call] = $position;
        }
        $sorted = $positions;
        asort($sorted);
        $this->assertSame(array_keys($positions), array_keys($sorted), "L'ordre des contrôles n'est plus celui de l'ADR 0040 décision 26 (verrou, lecture en lot, auteur, usage, profondeur, secret).");
        $this->assertSame(1, substr_count($source, '->findByIds('), 'Les recettes utilisées se lisent EN LOT, jamais une par une.');
        // L'auteur et la visibilité de qui écrit sont comparés à la recette ÉCRITE, jamais à l'administrateur qui la modifie
        $this->assertMatchesRegularExpression('/assertFound\(\$recipe, \$component, \$editorId\)/', $source);
    }

    public function testTheAuthorRuleComparesWithTheAuthorOfTheRecipeAndTheVisibilityOfTheEditor(): void
    {
        // Given
        $source = (string) file_get_contents((string) new ReflectionClass(RecipeComponentRules::class)->getFileName());

        // Then
        $this->assertMatchesRegularExpression('/\$parent->getAuthorId\(\) !== \$component->getAuthorId\(\)/', $source, "La recette utilisée doit être du MÊME auteur que la recette écrite.");
        $this->assertMatchesRegularExpression('/\$component->isVisibleTo\(\$editorId\)/', $source, "La recette secrète d'un autre n'existe pas pour qui écrit, administrateur compris.");
    }

    public function testAnUndeclaredCommandThatReceivesUsedRecipesIsDetected(): void
    {
        // Given : un répertoire d'application qui contient une nouvelle écriture à recettes utilisées, et d'autres classes sans rapport
        $directory = sys_get_temp_dir() . '/sub-recipe-writes-' . bin2hex(random_bytes(4));
        mkdir($directory . '/Repas/Application/Command/ComposeMeal', 0777, true);
        mkdir($directory . '/Repas/Application/Query/GetRecipe', 0777, true);
        file_put_contents($directory . '/Repas/Application/Command/ComposeMeal/ComposeMealCommand.php', "<?php\nreadonly class ComposeMealCommand\n{\n    /** @param list<string> \$componentIds */\n    public function __construct(public array \$componentIds)\n    {\n    }\n}\n");
        file_put_contents($directory . '/Repas/Application/Command/ComposeMeal/OtherCommand.php', "<?php\nreadonly class OtherCommand\n{\n    public function __construct(public string \$name, public int \$serving)\n    {\n    }\n}\n");
        file_put_contents($directory . '/Repas/Application/Query/GetRecipe/RecipeDTO.php', "<?php\nreadonly class RecipeDTO\n{\n    public function __construct(public array \$components)\n    {\n    }\n}\n");

        $carriers = [];
        try {
            // When
            $carriers = self::carriersIn($directory);
        } finally {
            $cleanup = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($cleanup as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }

        // Then : la nouvelle écriture est repérée, ni la lecture (un DTO), ni la commande sans recette utilisée
        $this->assertSame(['ComposeMealCommand'], array_map(static fn(string $class): string => str_contains($class, '\\') ? substr($class, strrpos($class, '\\') + 1) : $class, $carriers));
    }

    /**
     * Les commandes (`Application/Command`) de `$root` qui portent des recettes utilisées.
     *
     * @return list<class-string>
     */
    private static function carriersIn(string $root): array
    {
        $carriers = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if ($file->getExtension() !== 'php' || preg_match('#/Application/Command/.+(?<!Sub)Command\.php$#', $path) !== 1) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match(self::CARRIER, $source) !== 1 || preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
                // Une commande de test sans espace de noms (le répertoire d'essai) : son nom suffit
                if (preg_match(self::CARRIER, $source) === 1) {
                    $carriers[] = $file->getBasename('.php');
                }
                continue;
            }
            $carriers[] = $namespace[1] . '\\' . $file->getBasename('.php');
        }
        sort($carriers);

        return $carriers;
    }
}
