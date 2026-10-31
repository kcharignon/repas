<?php

namespace Repas\Tests\Drive\Infrastructure\Feature;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Repas\Drive\Infrastructure\Feature\EnvDriveFeature;
use Stringable;

class EnvDriveFeatureTest extends TestCase
{
    /** Des identifiants de test : ce que `Shared` reçoit du registre, il n'en connaît aucun. */
    private const array KNOWN = ['alpha', 'beta'];

    public function testIsEnabledWhenTheSwitchIsOn(): void
    {
        // Given
        $feature = new EnvDriveFeature(true, 'alpha', self::KNOWN, new NullLogger());

        // When
        $enabled = $feature->isEnabled();

        // Then
        $this->assertTrue($enabled);
    }

    public function testIsDisabledWhenTheSwitchIsOff(): void
    {
        // Given
        $feature = new EnvDriveFeature(false, 'alpha', self::KNOWN, new NullLogger());

        // When
        $enabled = $feature->isEnabled();

        // Then
        $this->assertFalse($enabled);
    }

    public function testTheGlobalSwitchSaysNothingAboutTheRetailers(): void
    {
        // Given : le global coupé, deux enseignes listées
        $feature = new EnvDriveFeature(false, 'alpha,beta', self::KNOWN, new NullLogger());

        // Then : la liste des enseignes est ce qu'elle est (le global l'emporte à part, dans le listener)
        $this->assertSame(['alpha', 'beta'], $feature->enabledRetailers());
        $this->assertTrue($feature->isRetailerEnabled('beta'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function lists(): iterable
    {
        yield 'une enseigne' => ['alpha', ['alpha']];
        yield 'deux enseignes, l\'ordre de la variable est gardé' => ['beta,alpha', ['beta', 'alpha']];
        yield 'espaces autour des identifiants' => ["  alpha ,\tbeta  ", ['alpha', 'beta']];
        yield 'éléments vides ignorés' => [',alpha,,beta,', ['alpha', 'beta']];
        yield 'doublons retirés' => ['alpha,beta,alpha, beta', ['alpha', 'beta']];
        yield 'variable vide : aucune enseigne' => ['', []];
        yield 'que des espaces et des virgules : aucune enseigne' => [' , ,', []];
        yield 'la casse compte : un identifiant en majuscules est inconnu' => ['Alpha,beta', ['beta']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('lists')]
    public function testTheListIsParsed(string $variable, array $expected): void
    {
        // Given
        $feature = new EnvDriveFeature(true, $variable, self::KNOWN, new NullLogger());

        // Then
        $this->assertSame($expected, $feature->enabledRetailers());
        foreach (self::KNOWN as $retailer) {
            $this->assertSame(in_array($retailer, $expected, true), $feature->isRetailerEnabled($retailer));
        }
    }

    public function testAnUnknownRetailerIsIgnoredAndLoggedOnceAtStartup(): void
    {
        // Given
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };

        // When : un identifiant inconnu (deux fois) et un connu ; jamais une erreur
        $feature = new EnvDriveFeature(true, 'alpha, inconnue ,inconnue', self::KNOWN, $logger);

        // Then : ignoré, journalisé une fois, l'enseigne connue reste allumée
        $this->assertSame(['alpha'], $feature->enabledRetailers());
        $this->assertFalse($feature->isRetailerEnabled('inconnue'));
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0][0]);
        $this->assertSame(['retailer' => 'inconnue'], $logger->records[0][2]);
    }

    public function testNothingIsLoggedWhenEverythingIsKnown(): void
    {
        // Given
        $logger = new class extends AbstractLogger {
            public int $count = 0;

            public function log($level, string|Stringable $message, array $context = []): void
            {
                ++$this->count;
            }
        };

        // When
        new EnvDriveFeature(true, 'alpha,beta', self::KNOWN, $logger);
        new EnvDriveFeature(true, '', self::KNOWN, $logger);

        // Then
        $this->assertSame(0, $logger->count);
    }

    public function testAnUnknownIdentifierIsNeverEnabled(): void
    {
        // Given
        $feature = new EnvDriveFeature(true, 'alpha', self::KNOWN, new NullLogger());

        // Then
        $this->assertFalse($feature->isRetailerEnabled('beta'));
        $this->assertFalse($feature->isRetailerEnabled(''));
        $this->assertFalse($feature->isRetailerEnabled('inconnue'));
    }
}
