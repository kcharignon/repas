<?php

namespace Repas\Tests\Voice\Infrastructure\Feature;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Voice\Domain\Interface\VoiceFeature;
use Repas\Voice\Infrastructure\Feature\EnvVoiceFeature;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le port est câblé sur la variable d'environnement `ALEXA_ENABLED`, et il est COUPÉ par défaut : quand la ligne est vide ou
 * invalide, et surtout quand elle est ABSENTE — c'est ce qui rend la commande vocale inoffensive tant que l'humain ne l'a pas allumée
 * (ADR 0029 décision 8). L'environnement de test l'allume (`.env.test`, `phpunit.dist.xml`) : les tests des controllers de `Voice`
 * partent d'un interrupteur allumé, et essaient le cas coupé avec `ControlledVoiceFeature`.
 */
class VoiceFeatureWiringTest extends KernelTestCase
{
    public function testThePortIsWiredOnTheEnvironmentVariableAndTheTestEnvironmentTurnsItOn(): void
    {
        // Given
        self::bootKernel();

        // When
        $feature = self::getContainer()->get(VoiceFeature::class);

        // Then
        $this->assertInstanceOf(EnvVoiceFeature::class, $feature);
        $this->assertTrue($feature->isEnabled());
    }

    #[DataProvider('values')]
    public function testTheSwitchFollowsTheVariableAndIsOffWhenItIsAbsentEmptyOrInvalid(string|false $value, bool $expected): void
    {
        // Given : la variable à la valeur essayée (ou retirée de l'environnement), le noyau reconstruit
        $previous = [$_SERVER['ALEXA_ENABLED'] ?? null, $_ENV['ALEXA_ENABLED'] ?? null, getenv('ALEXA_ENABLED')];
        try {
            if ($value === false) {
                unset($_SERVER['ALEXA_ENABLED'], $_ENV['ALEXA_ENABLED']);
                putenv('ALEXA_ENABLED');
            } else {
                $_SERVER['ALEXA_ENABLED'] = $_ENV['ALEXA_ENABLED'] = $value;
                putenv('ALEXA_ENABLED=' . $value);
            }
            self::ensureKernelShutdown();
            self::bootKernel(['environment' => 'test', 'debug' => false]);

            // When
            $enabled = self::getContainer()->get(VoiceFeature::class)->isEnabled();

            // Then
            $this->assertSame($expected, $enabled);
        } finally {
            foreach (['_SERVER' => $previous[0], '_ENV' => $previous[1]] as $bag => $old) {
                if ($old === null) {
                    unset($GLOBALS[$bag]['ALEXA_ENABLED']);
                } else {
                    $GLOBALS[$bag]['ALEXA_ENABLED'] = $old;
                }
            }
            putenv($previous[2] === false ? 'ALEXA_ENABLED' : 'ALEXA_ENABLED=' . $previous[2]);
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
