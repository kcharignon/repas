<?php

namespace Repas\Tests\Repas\Application;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Repas\Drive\Application\Command\ForgetChoice\ForgetChoiceCommand;
use Repas\Drive\Application\Command\ForgetChoice\ForgetChoiceHandler;
use Repas\Drive\Application\Command\ReplaceIngredientPreference\ReplaceIngredientPreferenceCommand;
use Repas\Drive\Application\Command\ReplaceIngredientPreference\ReplaceIngredientPreferenceHandler;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceIntent;
use Repas\Drive\Application\Command\SaveProductChoices\SaveProductChoicesHandler;
use Repas\Drive\Application\Port\IngredientAliasCommandPort;
use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\AddIngredientToShoppingList\AddIngredientToShoppingListHandler;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientCommand;
use Repas\Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientHandler;
use Repas\Repas\Application\Command\ChooseIngredientList\ChooseIngredientListCommand;
use Repas\Repas\Application\Command\ChooseIngredientList\ChooseIngredientListHandler;
use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeHandler;
use Repas\Repas\Application\Command\CreateConversion\CreateConversionCommand;
use Repas\Repas\Application\Command\CreateIngredientConversion\CreateIngredientConversionCommand;
use Repas\Repas\Application\Command\CreateIngredientConversion\CreateIngredientConversionHandler;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeHandler;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Repas\Application\Command\DeleteIngredientConversion\DeleteIngredientConversionCommand;
use Repas\Repas\Application\Command\DeleteIngredientConversion\DeleteIngredientConversionHandler;
use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsCommand;
use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsHandler;
use Repas\Repas\Application\Command\RememberSpokenPhrase\RememberSpokenPhraseCommand;
use Repas\Repas\Application\Command\RememberSpokenPhrase\RememberSpokenPhraseHandler;
use Repas\Repas\Application\Command\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListCommand;
use Repas\Repas\Application\Command\RemoveIngredientToShoppingList\RemoveIngredientToShoppingListHandler;
use Repas\Repas\Application\Command\RemoveSpokenIngredient\RemoveSpokenIngredientCommand;
use Repas\Repas\Application\Command\RemoveSpokenIngredient\RemoveSpokenIngredientHandler;
use Repas\Repas\Application\Command\UpdateConversion\UpdateConversionCommand;
use Repas\Repas\Application\Command\UpdateIngredientConversion\UpdateIngredientConversionCommand;
use Repas\Repas\Application\Command\UpdateIngredientConversion\UpdateIngredientConversionHandler;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeHandler;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Interface\IngredientMatchCandidates;
use Repas\Repas\Domain\Service\IngredientMergePlanner;
use Repas\Repas\Domain\Service\IngredientNameMatcher;

/**
 * Garde-fou de l'ADR 0028 (décision 4, risque R1) : une écriture qui reçoit le slug d'un ingrédient doit le traduire par les alias de
 * l'utilisateur, sinon une ligne de recette, de liste ou de choix du drive retombe sur un ancien slug (celui d'un ancien commun dont
 * l'utilisateur a reçu une copie, ou d'un ingrédient fusionné) qui n'est plus à lui. Rien dans le langage n'oblige à y penser : ce test
 * oblige à le DIRE.
 *
 * Il parcourt le code de l'application (hors lectures) et échoue dès qu'une classe porte une propriété `ingredientSlug` ou
 * `ingredientSlugs` sans figurer ci-dessous. Ajouter une écriture qui reçoit un slug d'ingrédient impose donc de la déclarer ici, soit
 * avec le handler qui la résout (`IngredientSlugResolver` côté Repas, `IngredientAliasCommandPort` côté Drive), soit, motif écrit, parmi
 * les exemptions. Il vérifie aussi que chaque handler déclaré injecte son résolveur ET s'en sert.
 *
 * Côté `Drive`, une écriture qui pose ou remplace un choix sur un ingrédient va plus loin que la traduction : elle vérifie que le slug
 * répond à un ingrédient de l'utilisateur (`resolveOwned`). Seules les suppressions listées dans `DRIVE_REMOVALS` se contentent de `resolve`.
 *
 * Les lectures par slug (`GET /ingredients/{slug}`, `ListCompatibleUnits`…) ne sont pas des écritures.
 * Les écritures qui reçoivent un identifiant de recette ou de liste (planifier un repas) lisent leurs slugs dans l'agrégat déjà
 * enregistré. La COPIE d'une recette les dépose sur une AUTRE recette, celle d'un autre utilisateur : elle ne porte aucun slug, le garde-fou
 * ci-dessus ne peut pas la voir, elle est donc déclarée à part (`COPIES`) et vérifiée de la même façon (ING-004).
 */
class IngredientSlugWritesAreResolvedTest extends TestCase
{
    /**
     * Les classes qui portent un slug d'ingrédient reçu en écriture, avec le handler qui le traduit.
     *
     * @var array<class-string, class-string>
     */
    private const array RESOLVED = [
        CreateRecipeRowSubCommand::class => CreateRecipeHandler::class,
        UpdateRecipeRowSubCommand::class => UpdateRecipeHandler::class,
        AddIngredientToShoppingListCommand::class => AddIngredientToShoppingListHandler::class,
        AddSpokenIngredientCommand::class => AddSpokenIngredientHandler::class,
        RememberSpokenPhraseCommand::class => RememberSpokenPhraseHandler::class,
        RemoveIngredientToShoppingListCommand::class => RemoveIngredientToShoppingListHandler::class,
        RemoveSpokenIngredientCommand::class => RemoveSpokenIngredientHandler::class,
        ChoiceIntent::class => SaveProductChoicesHandler::class,
        ReplaceIngredientPreferenceCommand::class => ReplaceIngredientPreferenceHandler::class,
        ForgetChoiceCommand::class => ForgetChoiceHandler::class,
        ChooseIngredientListCommand::class => ChooseIngredientListHandler::class,
        CreateIngredientConversionCommand::class => CreateIngredientConversionHandler::class,
        UpdateIngredientConversionCommand::class => UpdateIngredientConversionHandler::class,
        DeleteIngredientConversionCommand::class => DeleteIngredientConversionHandler::class,
    ];

    /**
     * Les écritures de `Drive` qui ne font que RETIRER ce qui est déjà à l'utilisateur : traduire le slug par les alias suffit (`resolve`),
     * aucun choix n'est posé sur un ingrédient. Toute autre écriture de `Drive` déclarée dans `RESOLVED` pose ou remplace un choix sur un
     * ingrédient et doit en plus vérifier qu'il est à l'utilisateur (`resolveOwned`).
     *
     * @var array<class-string, string> le handler, avec la raison
     */
    private const array DRIVE_REMOVALS = [
        ForgetChoiceHandler::class => "ne fait que supprimer la ligne de l'utilisateur sous le slug traduit : rien n'est posé sur un ingrédient",
    ];

    /**
     * Les écritures qui reprennent les slugs d'ingrédients d'un agrégat existant pour les poser chez un AUTRE utilisateur, avec le handler qui
     * les rapproche de ce que le copieur possède (`IngredientSlugResolver`, `IngredientMatchCandidates`). Une nouvelle écriture de ce genre
     * (copier une liste, partager une recette…) s'ajoute ici.
     *
     * @var array<class-string, class-string>
     */
    private const array COPIES = [
        CopyRecipeCommand::class => CopyRecipeHandler::class,
    ];

    /**
     * La fusion de deux ingrédients de l'utilisateur (ING-005) reçoit deux slugs (`absorbedSlug`, `keptSlug`) mais ne les « résout » pas : il
     * faut que ce soient SES ingrédients, tels quels ; l'ingrédient d'un autre est inconnu (404).
     * Son handler garde pourtant les alias à jour (`IngredientAliasRepository::mergeInto`) et reconnaît un rejeu par l'alias de l'absorbé
     * (`find`) : c'est ce qui fait que les anciens slugs, envoyés par les écritures ci-dessus, atterrissent sur l'ingrédient gardé.
     *
     * @var array<class-string, class-string>
     */
    private const array MERGES = [
        MergeIngredientsCommand::class => MergeIngredientsHandler::class,
    ];

    /**
     * Les classes dispensées, avec la raison : elles ne reçoivent jamais un ancien slug à traduire.
     *
     * @var array<class-string, string>
     */
    private const array EXEMPT = [
        CreateConversionCommand::class => "réservée aux administrateurs (#[IsGranted('ROLE_ADMIN')]) et à leurs PROPRES ingrédients (le handler refuse celui d'un autre, D4) : le slug est tel quel",
        UpdateConversionCommand::class => "réservée aux administrateurs (#[IsGranted('ROLE_ADMIN')]) et à leurs PROPRES ingrédients (le handler refuse celui d'un autre, D4) : le slug est tel quel",
    ];

    public function testEveryApplicationClassThatReceivesAnIngredientSlugIsDeclared(): void
    {
        // Given
        $carriers = self::carriersIn(dirname(__DIR__, 3) . '/src');

        // When
        $undeclared = self::undeclared($carriers);

        // Then
        $this->assertSame(
            [],
            $undeclared,
            "Ces classes reçoivent un slug d'ingrédient en écriture sans être déclarées : résous le slug par les alias de l'utilisateur"
            . " (IngredientSlugResolver côté Repas, IngredientAliasCommandPort côté Drive, ADR 0028 décision 4) puis ajoute-les à RESOLVED,"
            . ' ou à EXEMPT avec la raison.',
        );
    }

    public function testNoDeclarationIsLeftBehindOnceTheClassNoLongerCarriesASlug(): void
    {
        // Given
        $carriers = self::carriersIn(dirname(__DIR__, 3) . '/src');

        // Then : une liste qui garde des classes disparues ne protégerait plus rien
        $this->assertSame([], array_values(array_diff(array_keys(self::RESOLVED), $carriers)), 'RESOLVED cite une classe qui ne porte plus de slug.');
        $this->assertSame([], array_values(array_diff(array_keys(self::EXEMPT), $carriers)), 'EXEMPT cite une classe qui ne porte plus de slug.');
    }

    public function testEveryDeclaredHandlerInjectsItsResolverAndUsesIt(): void
    {
        foreach (array_unique(array_values(self::RESOLVED)) as $handler) {
            // Given
            $reflection = new ReflectionClass($handler);
            $injected = array_filter(
                $reflection->getConstructor()?->getParameters() ?? [],
                static fn(\ReflectionParameter $parameter): bool => in_array((string) $parameter->getType(), [IngredientSlugResolver::class, IngredientAliasCommandPort::class], true),
            );
            $source = (string) file_get_contents((string) $reflection->getFileName());

            // Then
            $this->assertCount(1, $injected, "$handler doit injecter un résolveur de slugs (IngredientSlugResolver ou IngredientAliasCommandPort).");
            $property = array_values($injected)[0]->getName();
            $this->assertMatchesRegularExpression(
                '/\$this->' . preg_quote($property, '/') . '->resolve(?:Owned)?\(/',
                $source,
                "$handler injecte son résolveur de slugs mais ne s'en sert pas.",
            );
        }
    }

    public function testEveryDriveWriteThatPutsAChoiceOnAnIngredientChecksItBelongsToTheUser(): void
    {
        // Given : les handlers de Drive déclarés, hors ceux qui ne font que retirer
        $handlers = array_filter(
            array_unique(array_values(self::RESOLVED)),
            static fn(string $handler): bool => str_starts_with($handler, 'Repas\\Drive\\') && !isset(self::DRIVE_REMOVALS[$handler]),
        );

        foreach ($handlers as $handler) {
            // Then : la traduction seule (`resolve`) ne suffit pas, le slug doit répondre à un ingrédient de l'utilisateur
            $reflection = new ReflectionClass($handler);
            $this->assertTrue(
                self::usesOwnedResolution((string) file_get_contents((string) $reflection->getFileName()), $handler),
                "$handler pose un choix sur un ingrédient : il doit vérifier qu'il est à l'utilisateur (IngredientAliasCommandPort::resolveOwned).",
            );
        }
        $this->assertNotEmpty($handlers);
        foreach (array_keys(self::DRIVE_REMOVALS) as $handler) {
            $this->assertContains($handler, self::RESOLVED, "$handler n'est plus déclaré : retire-le de DRIVE_REMOVALS.");
        }
    }

    public function testATranslationAloneIsNotTakenForAnOwnershipCheck(): void
    {
        // Given : un handler fictif qui ne fait que traduire, et un autre qui vérifie
        $translates = new class(new class implements IngredientAliasCommandPort {
            public function resolve(string $userId, array $slugs): array
            {
                return [];
            }

            public function resolveOwned(string $userId, array $slugs): array
            {
                return [];
            }
        }) {
            public function __construct(public IngredientAliasCommandPort $aliases)
            {
            }
        };
        $handler = $translates::class;

        // Then
        $this->assertFalse(self::usesOwnedResolution('$this->aliases->resolve($user, $slugs);', $handler));
        $this->assertTrue(self::usesOwnedResolution('$this->aliases->resolveOwned($user, $slugs);', $handler));
        $this->assertFalse(self::usesOwnedResolution('$this->other->resolveOwned($user, $slugs);', $handler), 'seul le résolveur injecté compte');
    }

    public function testEveryCopyUsesTheResolverAndTheNameMatchingOfItsHandler(): void
    {
        foreach (self::COPIES as $command => $handler) {
            // Given
            $reflection = new ReflectionClass($handler);
            $types = array_map(
                static fn(\ReflectionParameter $parameter): string => (string) $parameter->getType(),
                $reflection->getConstructor()?->getParameters() ?? [],
            );
            $source = (string) file_get_contents((string) $reflection->getFileName());

            // Then : il résout par la provenance (alias) ET compare les noms sur ce que le copieur voit
            $this->assertContains(IngredientSlugResolver::class, $types, "$handler doit injecter le résolveur de slugs.");
            $this->assertContains(IngredientMatchCandidates::class, $types, "$handler doit injecter les candidats du rapprochement.");
            $this->assertContains(IngredientNameMatcher::class, $types, "$handler doit injecter le rapprochement de noms.");
            $this->assertMatchesRegularExpression('/\$this->\w+->resolve\(/', $source, "$handler ne résout pas les slugs de la recette copiée.");
            $this->assertMatchesRegularExpression('/\$this->\w+->visibleTo\(/', $source, "$handler ne lit pas les ingrédients que le copieur voit.");
            $this->assertMatchesRegularExpression('/\$this->\w+->match\(/', $source, "$handler ne rapproche pas les noms.");
            // Une copie ne doit jamais écraser : un slug libre, jamais celui d'un ingrédient existant
            $this->assertMatchesRegularExpression('/\$this->\w+->forAdoption\(/', $source, "$handler doit choisir un slug libre (IngredientSlugPolicy).");
            $this->assertTrue(class_exists($command), "$command n'existe plus : retire sa déclaration.");
        }
    }

    public function testEveryMergeKeepsTheAliasesAndRecognizesAReplay(): void
    {
        foreach (self::MERGES as $command => $handler) {
            // Given
            $reflection = new ReflectionClass($handler);
            $types = array_map(
                static fn(\ReflectionParameter $parameter): string => (string) $parameter->getType(),
                $reflection->getConstructor()?->getParameters() ?? [],
            );
            $source = (string) file_get_contents((string) $reflection->getFileName());

            // Then : les alias suivent la fusion, un rejeu se reconnaît, l'ingrédient d'un autre est inconnu et le verdict est celui du service de domaine
            $this->assertContains(IngredientAliasRepository::class, $types, "$handler doit injecter les alias.");
            $this->assertMatchesRegularExpression('/\$this->\w+->mergeInto\(/', $source, "$handler doit déplacer les alias de l'absorbé sur le gardé.");
            $this->assertMatchesRegularExpression('/\$this->\w+->find\(/', $source, "$handler doit reconnaître le rejeu d'une fusion par l'alias de l'absorbé.");
            $this->assertMatchesRegularExpression('/->assertOwnedBy\(/', $source, "$handler doit refuser l'ingrédient d'un autre (404), sans passe-droit d'administrateur.");
            $this->assertContains(IngredientMergePlanner::class, $types, "$handler doit demander son verdict au service de domaine, le même que l'aperçu.");
            $this->assertTrue(class_exists($command), "$command n'existe plus : retire sa déclaration.");
        }
    }

    public function testTheExemptionsAreReallyReservedToAdministrators(): void
    {
        foreach (['CreateConversion', 'UpdateConversion'] as $useCase) {
            // Given
            $controller = (string) file_get_contents(dirname(__DIR__, 3) . "/src/Repas/Infrastructure/Http/Api/$useCase/{$useCase}ApiController.php");

            // Then
            $this->assertStringContainsString("#[IsGranted('ROLE_ADMIN')]", $controller, "$useCase n'est plus réservée aux administrateurs : retire l'exemption et résous son slug.");
        }
    }

    public function testAnUndeclaredCommandWithAnIngredientSlugIsDetected(): void
    {
        // Given : un répertoire d'application qui contient une nouvelle écriture à slug d'ingrédient, et d'autres classes sans rapport
        $directory = sys_get_temp_dir() . '/ingredient-slug-writes-' . bin2hex(random_bytes(4));
        mkdir($directory . '/Repas/Application/Command/ArchiveIngredient', 0777, true);
        mkdir($directory . '/Repas/Application/Query/GetIngredient', 0777, true);
        mkdir($directory . '/Repas/Application/Loader', 0777, true);
        file_put_contents($directory . '/Repas/Application/Command/ArchiveIngredient/ArchiveIngredientCommand.php', "<?php\nreadonly class ArchiveIngredientCommand\n{\n    public function __construct(\n        public string \$userId,\n        public string \$ingredientSlug,\n    ) {\n    }\n}\n");
        file_put_contents($directory . '/Repas/Application/Command/ArchiveIngredient/ArchiveManyCommand.php', "<?php\nreadonly class ArchiveManyCommand\n{\n    /** @param list<string> \$ingredientSlugs */\n    public function __construct(public array \$ingredientSlugs)\n    {\n    }\n}\n");
        file_put_contents($directory . '/Repas/Application/Command/ArchiveIngredient/OtherCommand.php', "<?php\nreadonly class OtherCommand\n{\n    public function __construct(public string \$departmentSlug, public ?string \$ingredientName)\n    {\n    }\n}\n");
        file_put_contents($directory . '/Repas/Application/Query/GetIngredient/IngredientDTO.php', "<?php\nreadonly class IngredientDTO\n{\n    public function __construct(public string \$ingredientSlug)\n    {\n    }\n}\n");
        file_put_contents($directory . '/Repas/Application/Loader/IngredientLoader.php', "<?php\ninterface IngredientLoader\n{\n    public function get(string \$ingredientSlug): array;\n}\n");

        $carriers = [];
        try {
            // When
            $carriers = self::carriersIn($directory);
        } finally {
            $cleanup = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($cleanup as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }

        // Then : les deux nouvelles écritures sont repérées, ni la lecture, ni le DTO, ni la classe sans slug d'ingrédient
        sort($carriers);
        $this->assertSame([
            'Repas\Repas\Application\Command\ArchiveIngredient\ArchiveIngredientCommand',
            'Repas\Repas\Application\Command\ArchiveIngredient\ArchiveManyCommand',
        ], $carriers);
        $this->assertSame($carriers, self::undeclared($carriers));
    }

    /**
     * Le code source appelle-t-il `resolveOwned()` sur le résolveur de slugs injecté dans la classe ?
     *
     * @param class-string $handler
     */
    private static function usesOwnedResolution(string $source, string $handler): bool
    {
        foreach ((new ReflectionClass($handler))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ((string) $parameter->getType() === IngredientAliasCommandPort::class
                && preg_match('/\$this->' . preg_quote($parameter->getName(), '/') . '->resolveOwned\(/', $source) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $carriers
     * @return list<string> celles qui ne sont ni déclarées résolues, ni dispensées
     */
    private static function undeclared(array $carriers): array
    {
        $declared = [...array_keys(self::RESOLVED), ...array_keys(self::EXEMPT)];

        return array_values(array_filter($carriers, static fn(string $class): bool => !in_array($class, $declared, true)));
    }

    /**
     * Les classes du code d'application qui déclarent une propriété `ingredientSlug` ou `ingredientSlugs`, hors lectures (`Query`,
     * `Loader`, `Dto`) : ce sont les commandes, leurs lignes et leurs intentions.
     *
     * @return list<string>
     */
    private static function carriersIn(string $sourceDirectory): array
    {
        $carriers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $fileInfo) {
            $file = $fileInfo->getPathname();
            $relative = substr($file, strlen($sourceDirectory) + 1, -strlen('.php'));
            // Le code d'application, sans les lectures ni les objets de transfert de lecture (`Dto`, et les `…DTO` qu'un Open Host Service de lecture publie)
            if (!str_ends_with($file, '.php') || !str_contains("/$relative", '/Application/') || preg_match('#/Application/(Query|Loader|Dto)/|/Application/OpenHostService/\\w+DTO$#', "/$relative")) {
                continue;
            }
            $source = (string) file_get_contents($file);
            // Une propriété (déclarée ou promue par le constructeur), pas un paramètre de méthode ni une variable locale
            if (preg_match('/\b(?:public|protected|private)\s+(?:readonly\s+)?\??[\w\\\\]+(?:\[\])?\s+\$ingredientSlugs?\b/', $source) === 1) {
                $carriers[] = 'Repas\\' . str_replace('/', '\\', $relative);
            }
        }
        sort($carriers);

        return $carriers;
    }
}
