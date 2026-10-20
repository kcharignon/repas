<?php

namespace Repas\Tests\Shared\Infrastructure\Feature;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Shared\Application\Interface\IngredientSeedingFeature;
use Repas\Shared\Infrastructure\Feature\EnvIngredientSeedingFeature;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le port est câblé sur la variable d'environnement `INGREDIENT_SEEDING_ENABLED`, et elle est COUPÉE par défaut : en test (`.env.test`,
 * `phpunit.dist.xml`), quand la ligne est vide ou invalide, et surtout quand elle est ABSENTE — c'est ce qui rend l'amorçage automatique
 * inoffensif tant que l'humain ne l'a pas allumé (ADR 0028, amendement du 2026-10-20).
 */
class IngredientSeedingFeatureWiringTest extends KernelTestCase
{
    public function testThePortIsWiredOnTheEnvironmentVariableAndTheTestEnvironmentTurnsItOff(): void
    {
        // Given
        self::bootKernel();

        // When
        $feature = self::getContainer()->get(IngredientSeedingFeature::class);

        // Then
        $this->assertInstanceOf(EnvIngredientSeedingFeature::class, $feature);
        $this->assertFalse($feature->isEnabled());
    }

    #[DataProvider('values')]
    public function testTheSwitchFollowsTheVariableAndIsOffWhenItIsAbsentEmptyOrInvalid(string|false $value, bool $expected): void
    {
        // Given : la variable à la valeur essayée (ou retirée de l'environnement), le noyau reconstruit
        $previous = [$_SERVER['INGREDIENT_SEEDING_ENABLED'] ?? null, $_ENV['INGREDIENT_SEEDING_ENABLED'] ?? null, getenv('INGREDIENT_SEEDING_ENABLED')];
        try {
            if ($value === false) {
                unset($_SERVER['INGREDIENT_SEEDING_ENABLED'], $_ENV['INGREDIENT_SEEDING_ENABLED']);
                putenv('INGREDIENT_SEEDING_ENABLED');
            } else {
                $_SERVER['INGREDIENT_SEEDING_ENABLED'] = $_ENV['INGREDIENT_SEEDING_ENABLED'] = $value;
                putenv('INGREDIENT_SEEDING_ENABLED=' . $value);
            }
            self::ensureKernelShutdown();
            self::bootKernel(['environment' => 'test', 'debug' => false]);

            // When
            $enabled = self::getContainer()->get(IngredientSeedingFeature::class)->isEnabled();

            // Then
            $this->assertSame($expected, $enabled);
        } finally {
            foreach (['_SERVER' => $previous[0], '_ENV' => $previous[1]] as $bag => $old) {
                if ($old === null) {
                    unset($GLOBALS[$bag]['INGREDIENT_SEEDING_ENABLED']);
                } else {
                    $GLOBALS[$bag]['INGREDIENT_SEEDING_ENABLED'] = $old;
                }
            }
            putenv($previous[2] === false ? 'INGREDIENT_SEEDING_ENABLED' : 'INGREDIENT_SEEDING_ENABLED=' . $previous[2]);
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
