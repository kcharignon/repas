<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsCommand;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Model\User;

/**
 * Le même nom dans deux rayons chez le même propriétaire (ADR 0028, amendement du 2026-10-30, ING-016) sur la vraie base et les vrais bus :
 * l'amorçage d'un compte et la copie d'une recette ne mélangent jamais deux ingrédients de même nom (chacun garde son rayon, son origine et un
 * identifiant à lui), et un nom déjà présent dans le bon rayon est rapproché au lieu d'être dupliqué. La création et la modification (409) sont
 * prouvées par `IngredientWriteApiFlowTest`, la voix par `SpokenIngredientCreatorFlowTest`.
 *
 * Valeurs factices uniquement.
 */
class SameNameInTwoDepartmentsTest extends DatabaseTestCase
{
    private DataPersister $persister;
    private CommandBusInterface $bus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->persister = new DataPersister(static::getContainer());
        $this->bus = static::getContainer()->get(CommandBusInterface::class);
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        foreach ([new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo()] as $unit) {
            $this->persister->saveUnit($unit->build());
        }
    }

    private function savedUser(?UserBuilder $builder = null): User
    {
        $user = ($builder ?? new UserBuilder())->build();
        $this->persister->saveUser($user);

        return $user;
    }

    /**
     * « Petits pois » (en grammes) de `$owner`, dans le rayon donné, sous le slug donné.
     */
    private function peas(User $owner, DepartmentBuilder $department, string $slug, bool $isBase = false): Ingredient
    {
        $ingredient = new IngredientBuilder()->isPasta()->withName('Petits pois')->withSlug($slug)->withDepartment($department)->withCreator($owner)->withBase($isBase)->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    /**
     * @return list<array{slug: string, department: string, adopted_from: ?string}>
     */
    private function ingredientsOf(User $user): array
    {
        /** @var list<array{slug: string, department: string, adopted_from: ?string}> */
        return $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT slug, department, adopted_from FROM ingredient WHERE creator = ? ORDER BY slug',
            [$user->getId()],
        );
    }

    public function testSeedingAnAccountCopiesBothIngredientsOfTheSameNameEachWithItsOwnDepartmentOriginAndSlug(): void
    {
        // Given : le propriétaire du catalogue a « Petits pois » en céréale ET en viande (rayons de test), les deux « de base »
        $owner = $this->savedUser(new UserBuilder()->isCatalogOwner());
        $cereal = $this->peas($owner, new DepartmentBuilder()->isCereal(), 'petits-pois-cereale', isBase: true);
        $meat = $this->peas($owner, new DepartmentBuilder()->isMeat(), 'petits-pois-viande', isBase: true);
        $user = $this->savedUser();

        // When
        $this->bus->dispatch(new SeedUserIngredientsCommand($user->getId(), 'essai'));

        // Then : deux copies, deux identifiants distincts (le second reçoit `-2`), chacune dans le rayon et avec l'origine de SON ingrédient
        $copies = $this->ingredientsOf($user);
        $this->assertCount(2, $copies);
        $this->assertCount(2, array_unique(array_column($copies, 'slug')));
        $this->assertEqualsCanonicalizing(
            [$cereal->getSlug() => 'cereale', $meat->getSlug() => 'viande'],
            array_column($copies, 'department', 'adopted_from'),
        );
        $slugs = array_column($copies, 'slug');
        $this->assertEqualsCanonicalizing([StringTool::slugify('Petits pois' . $user->getId()), StringTool::slugify('Petits pois-2' . $user->getId())], $slugs);
        // et les anciens identifiants du catalogue se résolvent chacun vers sa copie
        $aliases = $this->entityManager->getConnection()->fetchAllKeyValue('SELECT alias_slug, ingredient_slug FROM ingredient_alias WHERE user_id = ?', [$user->getId()]);
        $this->assertEquals(array_column($copies, 'slug', 'adopted_from'), $aliases);
    }

    public function testCopyingARecipeWhoseIngredientIsInAnotherDepartmentCreatesACopyInsteadOfLinkingToTheSameNameElsewhere(): void
    {
        // Given : le copieur a « Petits pois » en céréale ; la recette de l'auteur utilise « Petits pois » en viande
        $copier = $this->savedUser();
        $own = $this->peas($copier, new DepartmentBuilder()->isCereal(), 'petits-pois-copieur');
        $author = $this->savedUser();
        $theirs = $this->peas($author, new DepartmentBuilder()->isMeat(), 'petits-pois-auteur');
        $recipe = new RecipeBuilder()->withAuthor($author)->addRow(
            new RecipeRowBuilder()->withIngredient($theirs)->withUnit(new UnitBuilder()->isGramme())->withQuantity(200),
        )->build();
        $this->persister->saveRecipe($recipe);

        // When
        $this->bus->dispatch(new CopyRecipeCommand($recipe->getId(), $copier->getId(), 'copie'));

        // Then : le copieur a maintenant les deux, chacun dans son rayon ; le sien n'a pas été pris pour celui de la recette
        $ingredients = $this->ingredientsOf($copier);
        $this->assertCount(2, $ingredients);
        $created = array_values(array_filter($ingredients, static fn(array $row): bool => $row['slug'] !== $own->getSlug()))[0];
        $this->assertSame(['viande', $theirs->getSlug()], [$created['department'], $created['adopted_from']]);
        $this->assertSame('cereale', array_values(array_filter($ingredients, static fn(array $row): bool => $row['slug'] === $own->getSlug()))[0]['department']);
        $copiedRow = $this->entityManager->getConnection()->fetchOne('SELECT ingredient FROM recipe_row WHERE recipe = ?', ['copie']);
        $this->assertSame($created['slug'], $copiedRow);
        // son identifiant est le premier libre (le sien porte un autre slug : le motif du nom et du compte est libre)
        $this->assertSame(StringTool::slugify('Petits pois' . $copier->getId()), $created['slug']);
    }

    public function testCopyingARecipeLinksToTheIngredientOfTheSameNameInTheSameDepartmentAndCreatesNothing(): void
    {
        // Given : le copieur a « Petits pois » en céréale ET en viande ; la recette utilise celui de la viande
        $copier = $this->savedUser();
        $this->peas($copier, new DepartmentBuilder()->isCereal(), 'petits-pois-cereale-copieur');
        $inMeat = $this->peas($copier, new DepartmentBuilder()->isMeat(), 'petits-pois-viande-copieur');
        $author = $this->savedUser();
        $theirs = $this->peas($author, new DepartmentBuilder()->isMeat(), 'petits-pois-auteur');
        $recipe = new RecipeBuilder()->withAuthor($author)->addRow(
            new RecipeRowBuilder()->withIngredient($theirs)->withUnit(new UnitBuilder()->isGramme())->withQuantity(200),
        )->build();
        $this->persister->saveRecipe($recipe);

        // When
        $this->bus->dispatch(new CopyRecipeCommand($recipe->getId(), $copier->getId(), 'copie'));

        // Then : rapproché du bon (même rayon), rien de créé
        $this->assertCount(2, $this->ingredientsOf($copier));
        $this->assertSame($inMeat->getSlug(), $this->entityManager->getConnection()->fetchOne('SELECT ingredient FROM recipe_row WHERE recipe = ?', ['copie']));
    }
}
