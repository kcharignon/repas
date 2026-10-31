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
        'src/Shared/Domain/Model/Dimension.php',
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
