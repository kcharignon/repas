<?php

namespace Repas\Tests\Shared\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fou définitif de l'ADR 0035 : `Shared` ne porte ni port, ni DTO, ni interrupteur, ni commande du métier d'un contexte. Il garde les
 * buses, le modèle de base, les outils, `AuthenticatedUser`, les exceptions de base, les événements de domaine (langage publié), les
 * commandes console d'infrastructure (sans métier) et les briques HTTP, de courrier, de limiteurs et de dépôt communes.
 *
 * `PERMANENT` est la liste EXACTE des fichiers de `Shared` : tout autre fichier fait échouer le test, et une entrée qui n'existe plus aussi.
 * Ajouter un fichier à `Shared` est donc une décision visible dans ce test, et se justifie : si le fichier parle du métier d'un seul
 * contexte, il appartient à ce contexte (ADR 0035, `docs/contextes/roadmap.md`).
 */
class SharedHoldsNoContextBusinessTest extends TestCase
{
    /** Tout ce que `Shared` contient, et rien d'autre. */
    private const array PERMANENT = [
        'src/Shared/Application/Interface/CommandBusInterface.php',
        'src/Shared/Application/Interface/QueryBusInterface.php',
        'src/Shared/Application/Interface/QueryInterface.php',
        'src/Shared/Application/Service/CommandBus.php',
        'src/Shared/Application/Service/QueryBus.php',
        'src/Shared/Domain/Event/IngredientCreatedEvent.php',
        'src/Shared/Domain/Event/IngredientRemovedEvent.php',
        'src/Shared/Domain/Event/RecipeCreatedEvent.php',
        'src/Shared/Domain/Event/RecipeRemovedEvent.php',
        'src/Shared/Domain/Event/RecipesOrIngredientsCreatedEvent.php',
        'src/Shared/Domain/Event/RecipesOrIngredientsRemovedEvent.php',
        'src/Shared/Domain/Event/ShoppingListCompletedEvent.php',
        'src/Shared/Domain/Event/UserActivatedEvent.php',
        'src/Shared/Domain/Event/UserDeletedEvent.php',
        'src/Shared/Domain/Exception/DomainException.php',
        'src/Shared/Domain/Exception/RateLimitedException.php',
        'src/Shared/Domain/Interface/AuthenticatedUser.php',
        'src/Shared/Domain/Interface/UuidGenerator.php',
        'src/Shared/Domain/Model/ModelInterface.php',
        'src/Shared/Domain/Model/ModelTrait.php',
        'src/Shared/Domain/Tool/Clock.php',
        'src/Shared/Domain/Tool/JaroWinkler.php',
        'src/Shared/Domain/Tool/SearchRelevance.php',
        'src/Shared/Domain/Tool/StringTool.php',
        'src/Shared/Domain/Tool/Tab.php',
        'src/Shared/Domain/Tool/UuidGenerator.php',
        'src/Shared/Infrastructure/Console/ExportTableJsonCommand.php',
        'src/Shared/Infrastructure/Console/ResetDatabaseCommand.php',
        'src/Shared/Infrastructure/Console/ResetOpCacheCommand.php',
        'src/Shared/Infrastructure/Http/ApiAuthenticationFailureListener.php',
        'src/Shared/Infrastructure/Http/ApiExceptionListener.php',
        'src/Shared/Infrastructure/Http/Api/Health/HealthApiController.php',
        'src/Shared/Infrastructure/Http/ClientAddressKey.php',
        'src/Shared/Infrastructure/Http/EntityVersion.php',
        'src/Shared/Infrastructure/Mailer/BrandedEmailContent.php',
        'src/Shared/Infrastructure/Mailer/BrandedEmailLayout.php',
        'src/Shared/Infrastructure/Mailer/BrandedEmailLink.php',
        'src/Shared/Infrastructure/RateLimiter/BoundedLockFactory.php',
        'src/Shared/Infrastructure/Repository/PostgreSQLRepository.php',
    ];

    private const string WATCHED = '#^src/Shared/#';

    public function testSharedHoldsExactlyWhatItIsAllowedTo(): void
    {
        // When
        $unknown = [];
        foreach (SourceFiles::matching(self::WATCHED) as $path => $source) {
            if (!in_array($path, self::PERMANENT, true)) {
                $unknown[] = $path;
            }
        }

        // Then
        $this->assertSame([], $unknown, 'Un port, un DTO, un interrupteur ou une commande d\'un contexte n\'a pas sa place dans Shared : Open Host Service du producteur, port et ACL du consommateur, ou dans le contexte lui-même (ADR 0035).');
    }

    public function testEveryAllowedFileStillExists(): void
    {
        // When
        $gone = array_values(array_filter(self::PERMANENT, static fn (string $path): bool => !is_file(SourceFiles::root() . '/' . $path)));

        // Then
        $this->assertSame([], $gone, 'Ces fichiers sont partis de Shared : les retirer de PERMANENT.');
    }

    public function testSharedImportsNoContext(): void
    {
        // When
        $imports = [];
        foreach (SourceFiles::matching(self::WATCHED) as $path => $source) {
            if (preg_match('/^use Repas\\\\(?:Repas|User|Drive|Voice)\\\\/m', SourceFiles::code($source)) === 1) {
                $imports[] = $path;
            }
        }

        // Then
        $this->assertSame([], $imports, 'Shared n\'importe aucun contexte.');
    }
}
