<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Repas\Repas\Domain\Interface\BaseIngredientClassification;
use Repas\Shared\Domain\Tool\StringTool;
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

    public function testTheCatalogHasTheYolkAndTheWhiteAsPartsOfTheEggWithTheEggDepartmentAndUnits(): void
    {
        // When : `make fixtures-load-dev`
        $this->load(['dev']);

        // Then : « Jaune d'œuf » et « Blanc d'œuf » sont des parties d'« Œuf » au ratio 1 (ADR 0038), de base, dans son rayon et avec ses unités
        $connection = $this->entityManager->getConnection();
        $rows = $connection->fetchAllAssociative("SELECT slug, name, parent_slug, parent_ratio, is_base, department, default_cooking_unit, default_purchase_unit, creator FROM ingredient WHERE slug IN ('oeuf', 'jaune-d-oeuf', 'blanc-d-oeuf') ORDER BY slug");
        $this->assertSame(['blanc-d-oeuf', 'jaune-d-oeuf', 'oeuf'], array_column($rows, 'slug'));
        $egg = $rows[2];
        foreach ([$rows[0], $rows[1]] as $part) {
            $this->assertSame(['oeuf', 1.0, true], [$part['parent_slug'], (float) $part['parent_ratio'], $part['is_base']], $part['slug']);
            $this->assertSame([$egg['department'], $egg['default_cooking_unit'], $egg['default_purchase_unit'], $this->ownerId], [$part['department'], $part['default_cooking_unit'], $part['default_purchase_unit'], $part['creator']], $part['slug']);
        }
        $this->assertSame(["Blanc d'œuf", "Jaune d'œuf", 'Œuf'], array_column($rows, 'name'));
        // l'œuf, lui, n'est la partie de personne ; et la seule colonne `parent_slug` du catalogue est celle de ces deux parties
        $this->assertNull($egg['parent_slug']);
        $this->assertSame(2, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE parent_slug IS NOT NULL'));
    }

    public function testTheDevelopmentDepartmentsGiveAFlakeToFrozenAndACanToCannedGoodsAndNothingToTheOthers(): void
    {
        // When : `make fixtures-load-dev`
        $this->load(['dev']);

        // Then : les données de développement seulement (la production se règle par l'écran des rayons, ING-016 B)
        $icons = $this->entityManager->getConnection()->fetchAllKeyValue('SELECT slug, icon FROM department');
        $this->assertSame('snowflake', $icons['surgele']);
        $this->assertSame('can', $icons['conserve']);
        $this->assertSame([], array_keys(array_filter($icons, static fn(?string $icon, string $slug): bool => $icon !== null && !in_array($slug, ['surgele', 'conserve'], true), ARRAY_FILTER_USE_BOTH)));
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
            // Une copie ne recopie jamais le parent de l'original (ADR 0038 décision 5) : le lien se refait par provenance (SUB-002)
            $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND (parent_slug IS NOT NULL OR parent_ratio IS NOT NULL)', [$id]), "parents recopiés chez $email");
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

    public function testEveryNameOfARecipeAndOfAnIngredientStartsWithACapital(): void
    {
        // When : `make fixtures-load-dev` puis `make fixtures-demo` (ADR 0030 : la première lettre des noms est en majuscule, les données de
        // développement n'ont pas à attendre d'être corrigées par une écriture)
        $this->load(['dev']);
        $this->load(['demo']);

        // Then : le catalogue, les recettes de référence et celles des comptes de démonstration
        $connection = $this->entityManager->getConnection();
        foreach (['ingredient', 'recipe'] as $table) {
            $names = $connection->fetchFirstColumn("SELECT DISTINCT name FROM $table");
            $this->assertGreaterThan(8, count($names), $table);
            $lowercase = array_values(array_filter($names, static fn(string $name): bool => StringTool::capitalizeFirst($name) !== $name));
            $this->assertSame([], $lowercase, "noms de $table qui ne commencent pas par une majuscule");
        }
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
