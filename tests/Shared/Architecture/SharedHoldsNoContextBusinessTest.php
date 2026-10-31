<?php

namespace Repas\Tests\Shared\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fou de l'ADR 0035 : `Shared` ne porte ni port ni DTO du métier d'un contexte. Il garde les buses, le modèle de base, les outils,
 * `AuthenticatedUser`, les exceptions de base et les événements de domaine (langage publié).
 *
 * `LEGACY` est un cliquet : ce qui y était avant la règle et qui part, lien après lien, dans un Open Host Service du producteur et un port
 * du consommateur (`docs/contextes/roadmap.md`). Il ne fait que diminuer : un nouveau fichier ne s'y ajoute pas, et le test échoue aussi
 * quand une entrée a disparu du disque, pour qu'on la retire de la liste. Vide, il disparaît avec ce test.
 */
class SharedHoldsNoContextBusinessTest extends TestCase
{
    /** Ce que `Shared` garde pour toujours dans ces dossiers. */
    private const array PERMANENT = [
        'src/Shared/Application/Interface/CommandBusInterface.php',
        'src/Shared/Application/Interface/QueryBusInterface.php',
        'src/Shared/Application/Interface/QueryInterface.php',
        'src/Shared/Domain/Exception/DomainException.php',
        'src/Shared/Domain/Exception/RateLimitedException.php',
    ];

    private const array LEGACY = [
        'src/Shared/Application/Dto/ChangedMealServings.php',
        'src/Shared/Application/Dto/CreatedIngredient.php',
        'src/Shared/Application/Dto/IngredientSuggestion.php',
        'src/Shared/Application/Dto/ListedLine.php',
        'src/Shared/Application/Dto/ListedMeal.php',
        'src/Shared/Application/Dto/PlannedSpokenMeal.php',
        'src/Shared/Application/Dto/RecipeSuggestion.php',
        'src/Shared/Application/Dto/RemovedSpokenMeal.php',
        'src/Shared/Application/Dto/ResolvedIngredient.php',
        'src/Shared/Application/Dto/ResolvedRecipe.php',
        'src/Shared/Application/Dto/ShoppingListForDrive.php',
        'src/Shared/Application/Dto/ShoppingListLineForDrive.php',
        'src/Shared/Application/Dto/SpokenChoice.php',
        'src/Shared/Application/Dto/SpokenListLookupResult.php',
        'src/Shared/Application/Dto/SpokenMealLookupResult.php',
        'src/Shared/Application/Dto/SpokenRecipeResolution.php',
        'src/Shared/Application/Dto/SpokenResolution.php',
        'src/Shared/Application/Interface/AddedItem.php',
        'src/Shared/Application/Interface/DriveFeature.php',
        'src/Shared/Application/Interface/IngredientAliasResolver.php',
        'src/Shared/Application/Interface/IngredientDepartmentResolver.php',
        'src/Shared/Application/Interface/IngredientNameLoader.php',
        'src/Shared/Application/Interface/IngredientSeedingFeature.php',
        'src/Shared/Application/Interface/OwnershipLoader.php',
        'src/Shared/Application/Interface/RemovedItem.php',
        'src/Shared/Application/Interface/ShoppingListForDriveLoader.php',
        'src/Shared/Application/Interface/SpokenIngredientCreator.php',
        'src/Shared/Application/Interface/SpokenIngredientResolver.php',
        'src/Shared/Application/Interface/SpokenListLookup.php',
        'src/Shared/Application/Interface/SpokenRecipeResolver.php',
        'src/Shared/Application/Interface/UnitSlugResolver.php',
        'src/Shared/Application/Interface/VoiceFeature.php',
        'src/Shared/Application/Interface/VoiceLinkOwnershipLoader.php',
        'src/Shared/Application/Interface/VoiceMealPlanner.php',
        'src/Shared/Application/Interface/VoiceShoppingList.php',
        'src/Shared/Domain/Exception/DriveFeatureException.php',
        'src/Shared/Domain/Exception/SpokenMealFailure.php',
        'src/Shared/Domain/Exception/VoiceFeatureException.php',
        'src/Shared/Domain/Model/Dimension.php',
        'src/Shared/Infrastructure/Feature/EnvDriveFeature.php',
        'src/Shared/Infrastructure/Feature/EnvIngredientSeedingFeature.php',
        'src/Shared/Infrastructure/Feature/EnvVoiceFeature.php',
        'src/Shared/Infrastructure/Http/DriveEnabledListener.php',
        'src/Shared/Infrastructure/Http/RequiresDrive.php',
        'src/Shared/Infrastructure/Http/RequiresVoice.php',
        'src/Shared/Infrastructure/Http/VoiceEnabledListener.php',
    ];

    /** Les dossiers où un port ou un DTO de contexte se glisserait. */
    private const string WATCHED = '#^src/Shared/(?:Application/(?:Interface|Dto)/|Domain/Exception/|Domain/Model/(?!ModelInterface|ModelTrait)|Infrastructure/(?:Feature|Http)/[A-Za-z]+\.php$)#';

    public function testSharedGainsNoPortOrDtoOfAContext(): void
    {
        // When
        $unknown = [];
        foreach (SourceFiles::matching(self::WATCHED) as $path => $source) {
            if (!in_array($path, self::PERMANENT, true) && !in_array($path, self::LEGACY, true) && !self::isTransverseHttpTool($path)) {
                $unknown[] = $path;
            }
        }

        // Then
        $this->assertSame([], $unknown, 'Un port ou un DTO d\'un contexte n\'a pas sa place dans Shared : Open Host Service du producteur, port et ACL du consommateur (ADR 0035).');
    }

    public function testEveryLegacyEntryStillExists(): void
    {
        // When
        $gone = array_values(array_filter(self::LEGACY, static fn (string $path): bool => !is_file(SourceFiles::root() . '/' . $path)));

        // Then
        $this->assertSame([], $gone, 'Ces fichiers sont partis de Shared : les retirer de LEGACY.');
    }

    /** Les outils HTTP transverses (adresse du client, version d'entité, écouteurs d'API) ne sont pas du métier. */
    private static function isTransverseHttpTool(string $path): bool
    {
        return in_array(basename($path), ['ApiAuthenticationFailureListener.php', 'ApiExceptionListener.php', 'ClientAddressKey.php', 'EntityVersion.php'], true);
    }
}
