<?php

namespace Repas\Tests\Voice\Infrastructure\Feature;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Voice\Domain\Interface\VoiceTraceFeature;
use Repas\Voice\Infrastructure\Feature\EnvVoiceTraceFeature;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le port est câblé sur la variable d'environnement `ALEXA_TRACE_ENABLED`, et il est COUPÉ par défaut : quand la ligne est vide ou invalide, et surtout quand
 * elle est ABSENTE — c'est ce qui rend la trace inoffensive (aucune requête de plus) tant que l'humain ne l'a pas allumée (ADR 0029 décision 14). L'environnement
 * de test la coupe aussi (`.env.test`, `phpunit.dist.xml`) : les tests qui l'essaient remplacent le service par `ControlledVoiceTraceFeature`.
 */
class VoiceTraceFeatureWiringTest extends KernelTestCase
{
    public function testThePortIsWiredOnTheEnvironmentVariableAndTheTestEnvironmentTurnsItOff(): void
    {
        // Given
        self::bootKernel();

        // When
        $feature = self::getContainer()->get(VoiceTraceFeature::class);

        // Then
        $this->assertInstanceOf(EnvVoiceTraceFeature::class, $feature);
        $this->assertFalse($feature->isEnabled());
    }

    #[DataProvider('values')]
    public function testTheSwitchFollowsTheVariableAndIsOffWhenItIsAbsentEmptyOrInvalid(string|false $value, bool $expected): void
    {
        // Given : la variable à la valeur essayée (ou retirée de l'environnement), le noyau reconstruit
        $previous = [$_SERVER['ALEXA_TRACE_ENABLED'] ?? null, $_ENV['ALEXA_TRACE_ENABLED'] ?? null, getenv('ALEXA_TRACE_ENABLED')];
        try {
            if ($value === false) {
                unset($_SERVER['ALEXA_TRACE_ENABLED'], $_ENV['ALEXA_TRACE_ENABLED']);
                putenv('ALEXA_TRACE_ENABLED');
            } else {
                $_SERVER['ALEXA_TRACE_ENABLED'] = $_ENV['ALEXA_TRACE_ENABLED'] = $value;
                putenv('ALEXA_TRACE_ENABLED=' . $value);
            }
            self::ensureKernelShutdown();
            self::bootKernel(['environment' => 'test', 'debug' => false]);

            // When
            $enabled = self::getContainer()->get(VoiceTraceFeature::class)->isEnabled();

            // Then
            $this->assertSame($expected, $enabled);
        } finally {
            foreach (['_SERVER' => $previous[0], '_ENV' => $previous[1]] as $bag => $old) {
                if ($old === null) {
                    unset($GLOBALS[$bag]['ALEXA_TRACE_ENABLED']);
                } else {
                    $GLOBALS[$bag]['ALEXA_TRACE_ENABLED'] = $old;
                }
            }
            putenv($previous[2] === false ? 'ALEXA_TRACE_ENABLED' : 'ALEXA_TRACE_ENABLED=' . $previous[2]);
            self::ensureKernelShutdown();
        }
    }

    /**
     * @return iterable<string, array{string|false, bool}>
     */
    public static function values(): iterable
    {
        yield 'allumée' => ['true', true];
        yield 'allumée par 1' => ['1', true];
        yield 'coupée' => ['false', false];
        yield 'vide' => ['', false];
        yield 'invalide' => ['peut-être', false];
        yield 'absente' => [false, false];
    }
}
