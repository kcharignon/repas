<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Repas\Repas\Domain\Interface\BaseIngredientClassification;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;

/**
 * Les données de développement (`make fixtures-load-dev` puis `make fixtures-demo`) sur la VRAIE base, chargées par les vraies fixtures :
 * comme en production après la passation (ADR 0028, amendement du 2026-10-20, ING-014), tout ingrédient est à quelqu'un. Le catalogue est
 * au propriétaire, les ingrédients « de base » du classement validé portent le marqueur, et les comptes de démonstration reçoivent leurs
 * copies par l'amorçage (le vrai cas d'usage) avant de créer leurs recettes, qui ne désignent que des ingrédients à eux.
 */
class DevelopmentFixturesTest extends DatabaseTestCase
{
    private string $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        // Le propriétaire des tests (`INGREDIENT_CATALOG_OWNER_EMAIL`, force dans phpunit.dist.xml) existe avant les fixtures
        $owner = new UserBuilder()->isCatalogOwner()->build();
        new DataPersister(static::getContainer())->saveUser($owner);
        $this->ownerId = $owner->getId();
    }

    public function testTheCatalogBelongsToTheCatalogOwnerAndOnlyTheClassifiedOnesAreBase(): void
    {
        // When : `make fixtures-load-dev`
        $this->load(['dev']);

        // Then : aucun ingrédient sans créateur, tous chez le propriétaire du catalogue
        $connection = $this->entityManager->getConnection();
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL'));
        $this->assertGreaterThan(200, (int) $connection->fetchOne('SELECT count(*) FROM ingredient'));
        $this->assertSame([$this->ownerId], $connection->fetchFirstColumn('SELECT DISTINCT creator FROM ingredient'));

        // …et le marqueur « de base » est celui du classement validé, ni plus ni moins
        $classified = array_keys(array_filter(static::getContainer()->get(BaseIngredientClassification::class)->all()));
        $marked = $connection->fetchFirstColumn('SELECT slug FROM ingredient WHERE is_base ORDER BY slug');
        $existing = $connection->fetchFirstColumn('SELECT slug FROM ingredient');
        $expected = array_values(array_intersect($classified, $existing));
        sort($expected);
        $this->assertGreaterThan(100, count($expected));
        $this->assertSame($expected, $marked);
    }

    public function testTheDemoAccountsAreSeededAndTheirRecipesUseOnlyTheirOwnIngredients(): void
    {
        // Given : les données de référence
        $this->load(['dev']);
        $connection = $this->entityManager->getConnection();
        $catalogSize = (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE is_base');

        // When : `make fixtures-demo`, deux fois (idempotente)
        $this->load(['demo']);
        $this->load(['demo']);

        // Then : chaque compte de démonstration a ses copies de base (une fois), ses recettes, et aucune ligne ne désigne l'ingrédient d'un autre
        foreach (['user@repas.fr', 'admin@repas.fr'] as $email) {
            $id = $connection->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
            $this->assertSame($catalogSize, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$id]), "copies de $email");
            $this->assertSame(
                0,
                (int) $connection->fetchOne('SELECT count(*) FROM recipe_row x JOIN recipe r ON r.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient WHERE r.author = ? AND i.creator <> r.author', [$id]),
                "lignes de recette de $email sur l'ingrédient d'un autre",
            );
        }
        $this->assertSame(8, (int) $connection->fetchOne('SELECT count(*) FROM recipe WHERE author = (SELECT id FROM "user" WHERE email = ?)', ['user@repas.fr']));
        $this->assertSame(2, (int) $connection->fetchOne('SELECT count(*) FROM recipe WHERE author = (SELECT id FROM "user" WHERE email = ?)', ['admin@repas.fr']));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL'));
    }

    /**
     * @param list<string> $groups
     */
    private function load(array $groups): void
    {
        $loader = static::getContainer()->get('doctrine.fixtures.loader');
        new ORMExecutor($this->entityManager)->execute($loader->getFixtures($groups), true);
        $this->entityManager->clear();
    }
}
