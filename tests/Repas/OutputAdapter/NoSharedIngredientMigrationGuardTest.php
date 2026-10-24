<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20261024120000;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\DatabaseTestCase;

require_once dirname(__DIR__, 3) . '/migrations/Version20261024120000.php';

/**
 * La migration-garde d'ING-014 sur la VRAIE base : en production elle refuse de démarrer tant qu'un ingrédient n'a pas de créateur (la
 * passation des anciens communs n'est pas faite), sinon elle passe ; hors production elle avertit seulement.
 */
class NoSharedIngredientMigrationGuardTest extends DatabaseTestCase
{
    private mixed $environment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->environment = $_SERVER['APP_ENV'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->environment === null) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $this->environment;
        }

        parent::tearDown();
    }

    public function testProductionRefusesToStartWhileAnIngredientHasNoCreator(): void
    {
        // Given : un ancien commun et un ingrédient à quelqu'un, en production
        $_SERVER['APP_ENV'] = 'prod';
        $this->insertIngredient('beurre', null);
        $this->insertIngredient('farine-user', 'user-id');

        // Then
        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessageMatches('/1 ingrédient\(s\) sans créateur.*passation/');

        // When
        $this->migration()->up(new Schema());
    }

    public function testProductionStartsOnceEveryIngredientHasACreator(): void
    {
        // Given : la base d'après la passation, et la base vide d'un premier déploiement
        $_SERVER['APP_ENV'] = 'prod';
        $this->migration()->up(new Schema());
        $this->insertIngredient('beurre-user', 'user-id');

        // When
        $migration = $this->migration();
        $migration->up(new Schema());

        // Then : aucune erreur, et aucune écriture (seule l'instruction neutre)
        $this->assertSame(['SELECT 1'], array_map(static fn($query): string => $query->getStatement(), $migration->getSql()));
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM ingredient'));
    }

    public function testDevelopmentAndTestAreNotBlocked(): void
    {
        // Given : un ancien commun, hors production
        $this->insertIngredient('beurre', null);

        foreach (['dev', 'test'] as $environment) {
            $_SERVER['APP_ENV'] = $environment;

            // When
            $migration = $this->migration();
            $migration->up(new Schema());

            // Then : pas d'abandon, seulement l'instruction neutre
            $this->assertCount(1, $migration->getSql(), $environment);
        }
    }

    private function migration(): Version20261024120000
    {
        return new Version20261024120000($this->entityManager->getConnection(), new NullLogger());
    }

    private function insertIngredient(string $slug, ?string $creator): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO ingredient (slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, creator, compatible_units, adopted_from)'
            . " VALUES (?, ?, '', ?, 'epicerie', 'gramme', 'gramme', ?, '[]', NULL)",
            [$slug, $slug, $slug, $creator],
        );
    }
}
