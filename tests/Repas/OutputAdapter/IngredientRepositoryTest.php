<?php

namespace Repas\Tests\Repas\OutputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\RepasAssert;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Chaque test crée ses utilisateurs, rayons, unités et ingrédients (la base de test est vide au départ).
 */
class IngredientRepositoryTest extends DatabaseTestCase
{
    private IngredientRepository $ingredientRepository;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ingredientRepository = static::getContainer()->get(IngredientRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testFindPurchaseUnitSlugReadsTheUnitWithoutLoadingTheIngredient(): void
    {
        // Arrange
        $ingredient = new IngredientBuilder()->isPasta()->build();
        $this->persister->saveIngredient($ingredient);

        // Act & Assert
        $this->assertSame($ingredient->getDefaultPurchaseUnit()->getSlug(), $this->ingredientRepository->findPurchaseUnitSlug($ingredient->getSlug()));
    }

    public function testFindPurchaseUnitSlugUsableByGivesTheUnitOfTheCreatorsOwnIngredientOnly(): void
    {
        // Arrange : le propriétaire du catalogue (administrateur) et un autre compte ont chacun le leur
        $creator = new UserBuilder()->build();
        $admin = new UserBuilder()->isAdmin()->build();
        $this->persister->saveUser($creator);
        $this->persister->saveUser($admin);
        $custom = new IngredientBuilder()->isButter()->withCreator($creator, true)->build();
        $adminsOwn = new IngredientBuilder()->isPasta()->withCreator($admin)->build();
        $this->persister->saveIngredient($custom);
        $this->persister->saveIngredient($adminsOwn);

        // Act & Assert
        $this->assertSame($custom->getDefaultPurchaseUnit()->getSlug(), $this->ingredientRepository->findPurchaseUnitSlugUsableBy($custom->getSlug(), $creator->getId()));
        $this->assertSame($adminsOwn->getDefaultPurchaseUnit()->getSlug(), $this->ingredientRepository->findPurchaseUnitSlugUsableBy($adminsOwn->getSlug(), $admin->getId()));
        // et aucun passe-droit : l'administrateur n'utilise pas l'ingrédient d'un autre
        $this->expectExceptionObject(IngredientException::notFound($custom->getSlug()));
        $this->ingredientRepository->findPurchaseUnitSlugUsableBy($custom->getSlug(), $admin->getId());
    }

    public function testFindPurchaseUnitSlugUsableByFailsForTheCustomIngredientOfAnotherUserAndForAnUnknownOne(): void
    {
        // Arrange
        $creator = new UserBuilder()->build();
        $this->persister->saveUser($creator);
        $custom = new IngredientBuilder()->isButter()->withCreator($creator, true)->build();
        $this->persister->saveIngredient($custom);

        // Assert & Act
        try {
            $this->ingredientRepository->findPurchaseUnitSlugUsableBy($custom->getSlug(), 'another-user-id');
            $this->fail("L'ingrédient personnalisé d'un autre est inconnu.");
        } catch (IngredientException $exception) {
            $this->assertEquals(IngredientException::notFound($custom->getSlug()), $exception);
        }
        $this->expectExceptionObject(IngredientException::notFound('inconnu'));
        $this->ingredientRepository->findPurchaseUnitSlugUsableBy('inconnu', $creator->getId());
    }

    public function testFindPurchaseUnitSlugFailsForAnUnknownIngredient(): void
    {
        // Assert
        $this->expectExceptionObject(IngredientException::notFound('inconnu'));

        // Act
        $this->ingredientRepository->findPurchaseUnitSlug('inconnu');
    }

    public function testFindBySlugsReturnsEachIngredientOnce(): void
    {
        // Arrange
        $egg = new IngredientBuilder()->isEgg()->build();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $milk = new IngredientBuilder()->isMilk()->build();
        foreach ([$egg, $pasta, $milk] as $ingredient) {
            $this->persister->saveIngredient($ingredient);
        }

        // Act
        $actual = $this->ingredientRepository->findBySlugs(Tab::fromArray($pasta->getSlug(), $egg->getSlug(), $pasta->getSlug()));

        // Assert
        RepasAssert::assertTabType(Tab::newEmptyTyped(Ingredient::class), $actual);
        $this->assertCount(2, $actual);
        $indexed = $actual->indexBy(fn(Ingredient $ingredient) => $ingredient->getSlug());
        RepasAssert::assertIngredient($pasta, $indexed[$pasta->getSlug()]);
        RepasAssert::assertIngredient($egg, $indexed[$egg->getSlug()]);
    }

    public function testFindBySlugsFailsForAnUnknownIngredient(): void
    {
        // Arrange
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->persister->saveIngredient($egg);

        // Assert
        $this->expectExceptionObject(IngredientException::notFound('inconnu'));

        // Act
        $this->ingredientRepository->findBySlugs(Tab::fromArray($egg->getSlug(), 'inconnu'));
    }

    public function testFindBySlugsReadsIngredientsDepartmentsAndUnitsInOneQueryEach(): void
    {
        // Arrange
        $egg = new IngredientBuilder()->isEgg()->build();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $milk = new IngredientBuilder()->isMilk()->build();
        foreach ([$egg, $pasta, $milk] as $ingredient) {
            $this->persister->saveIngredient($ingredient);
        }
        static::getContainer()->get('doctrine')->getManager()->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // Act
        $actual = $this->ingredientRepository->findBySlugs(Tab::fromArray($egg->getSlug(), $pasta->getSlug(), $milk->getSlug()));

        // Assert
        $this->assertCount(3, $actual);
        $this->assertSame(['SELECT ingredient' => 1, 'SELECT department' => 1, 'SELECT unit' => 1], $counter->byTable());
    }

    public function testCRUD(): void
    {
        // Arrange
        $ingredient = new IngredientBuilder()->build();
        $this->persister->saveIngredientDependencies($ingredient);

        // Act (insert)
        $this->ingredientRepository->save($ingredient);

        // Assert
        $loadedIngredient = $this->ingredientRepository->findOneBySlug($ingredient->getSlug());
        RepasAssert::assertIngredient($ingredient, $loadedIngredient);

        // Arrange
        $baby = new DepartmentBuilder()->isBaby()->build();
        $gramme = new UnitBuilder()->isGramme()->build();
        $this->persister->saveDepartment($baby);
        $this->persister->saveUnit($gramme);
        $ingredient->setName('nouveau nom');
        $ingredient->setImage('file://image/nouvelle/nouveau.jpg');
        $ingredient->setDepartment($baby);
        $ingredient->setDefaultCookingUnit($gramme);
        $ingredient->setDefaultPurchaseUnit($gramme);

        // Act (update)
        $this->ingredientRepository->save($ingredient);

        // Assert
        $loadedIngredient = $this->ingredientRepository->findOneBySlug($ingredient->getSlug());
        RepasAssert::assertIngredient($ingredient, $loadedIngredient);

        // Act (delete)
        $this->ingredientRepository->delete($ingredient);

        // Assert
        $this->expectExceptionObject(IngredientException::notFound($ingredient->getSlug()));
        $this->ingredientRepository->findOneBySlug($ingredient->getSlug());
    }

    public function testTheOriginOfAnAdoptionSurvivesTheDatabaseAndAnUpdate(): void
    {
        // Arrange : la copie d'un commun par un utilisateur, et un ingrédient créé par lui
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);
        $adoption = new IngredientBuilder()->isButter()->withCreator($user, true)->withAdoptedFrom('beurre')->build();
        $created = new IngredientBuilder()->isFlour()->withCreator($user, true)->build();
        $this->persister->saveIngredient($adoption);
        $this->persister->saveIngredient($created);
        $this->entityManager->clear();

        // Act
        $loadedAdoption = $this->ingredientRepository->findOneBySlug($adoption->getSlug());
        $loadedCreated = $this->ingredientRepository->findOneBySlug($created->getSlug());

        // Assert : lu en base (l'identity map est vide), pas rendu par le modèle enregistré
        RepasAssert::assertIngredient($adoption, $loadedAdoption);
        $this->assertSame('beurre', $loadedAdoption->getAdoptedFrom());
        $this->assertTrue($loadedAdoption->isAdoption());
        $this->assertNull($loadedCreated->getAdoptedFrom());
        $this->assertSame('beurre', $this->columnOf($adoption->getSlug(), 'adopted_from'));
        $this->assertNull($this->columnOf($created->getSlug(), 'adopted_from'));

        // Act : la copie est renommée par son propriétaire
        $loadedAdoption->setName('Beurre demi-sel');
        $this->ingredientRepository->save($loadedAdoption);
        $this->entityManager->clear();

        // Assert : l'origine est un fait immuable
        $renamed = $this->ingredientRepository->findOneBySlug($adoption->getSlug());
        $this->assertSame(['Beurre demi-sel', 'beurre'], [$renamed->getName(), $renamed->getAdoptedFrom()]);
    }

    public function testSavingAModelWithoutOriginDoesNotEraseTheOriginOfTheRow(): void
    {
        // Arrange : une ligne née d'une adoption, réécrite par un modèle qui ne connaît pas l'origine (le code précédent, une copie)
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);
        $adoption = new IngredientBuilder()->isButter()->withCreator($user, true)->withAdoptedFrom('beurre')->build();
        $this->persister->saveIngredient($adoption);
        $withoutOrigin = new IngredientBuilder()->isButter()->withCreator($user, true)->withName('Beurre')->build();

        // Act
        $this->ingredientRepository->save($withoutOrigin);
        $this->entityManager->clear();

        // Assert
        $this->assertSame('beurre', $this->columnOf($adoption->getSlug(), 'adopted_from'));
    }

    public function testTheBaseMarkerRoundTripsThroughTheDatabase(): void
    {
        // Arrange : un ingrédient marqué « de base » et un autre qui ne l'est pas
        $owner = new UserBuilder()->isCatalogOwner()->build();
        $this->persister->saveUser($owner);
        $base = new IngredientBuilder()->isButter()->withCreator($owner, true)->withBase()->build();
        $notBase = new IngredientBuilder()->isFlour()->withCreator($owner, true)->build();
        $this->persister->saveIngredient($base);
        $this->persister->saveIngredient($notBase);
        $this->entityManager->clear();

        // Act
        $loadedBase = $this->ingredientRepository->findOneBySlug($base->getSlug());
        $loadedNotBase = $this->ingredientRepository->findOneBySlug($notBase->getSlug());

        // Assert : lu en base (l'identity map est vide)
        $this->assertTrue($loadedBase->isBase());
        $this->assertFalse($loadedNotBase->isBase());
        $this->assertSame(true, $this->boolColumnOf($base->getSlug(), 'is_base'));
        $this->assertSame(false, $this->boolColumnOf($notBase->getSlug(), 'is_base'));

        // Act : un renommage garde le marqueur, puis le propriétaire le retire
        $loadedBase->setName('Beurre demi-sel');
        $this->ingredientRepository->save($loadedBase);
        $this->entityManager->clear();
        $renamed = $this->ingredientRepository->findOneBySlug($base->getSlug());
        $this->assertTrue($renamed->isBase());
        $renamed->changeBase(false);
        $this->ingredientRepository->save($renamed);
        $this->entityManager->clear();

        // Assert
        $this->assertFalse($this->ingredientRepository->findOneBySlug($base->getSlug())->isBase());
        $this->assertSame(false, $this->boolColumnOf($base->getSlug(), 'is_base'));
    }

    public function testARowInsertedWithoutTheColumnIsNotBase(): void
    {
        // Arrange : un `INSERT` comme celui du code d'avant la colonne, qui ne la connaît pas (ADR 0017 règle 6)
        $ingredient = new IngredientBuilder()->isButter()->build();
        $this->persister->saveIngredientDependencies($ingredient);
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO ingredient (slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, creator, compatible_units, adopted_from)'
            . " VALUES ('beurre', 'beurre', '', 'beurre', ?, ?, ?, NULL, '[]', NULL)",
            [$ingredient->getDepartment()->getSlug(), $ingredient->getDefaultCookingUnit()->getSlug(), $ingredient->getDefaultPurchaseUnit()->getSlug()],
        );

        // Act
        $loaded = $this->ingredientRepository->findOneBySlug('beurre');

        // Assert : la valeur par défaut de la base s'applique
        $this->assertFalse($loaded->isBase());
        $this->assertSame(false, $this->boolColumnOf('beurre', 'is_base'));
    }

    public static function getByDepartmentDataProvider(): array
    {
        $creator = new UserBuilder()->build();
        $admin = new UserBuilder()->isAdmin()->build();
        $otherUser = new UserBuilder()->build();

        return [
            'with creator and called by admin' => [$creator, $admin, false],
            'with creator and called by other user' => [$creator, $otherUser, false],
            'with creator and called by himself' => [$creator, $creator, true],
        ];
    }

    #[DataProvider('getByDepartmentDataProvider')]
    public function testGetByDepartment(User $creator, User $user, bool $see): void
    {
        // Arrange
        $this->persister->saveUser($creator);
        $this->persister->saveUser($user);
        $babyDepartment = new DepartmentBuilder()->isBaby()->build();
        $ingredient = new IngredientBuilder()
            ->onBabyDepartment()
            ->withCreator($creator)
            ->build();
        $this->persister->saveIngredient($ingredient);

        // Act
        $actual = $this->ingredientRepository->findByDepartmentAndOwner($babyDepartment, $user->getId());

        // Assert
        $slugs = $this->slugsOf($actual);
        $see ? $this->assertContains($ingredient->getSlug(), $slugs) : $this->assertNotContains($ingredient->getSlug(), $slugs);
    }

    public function testFindAll(): void
    {
        // Arrange
        $egg = new IngredientBuilder()->isEgg()->build();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->persister->saveIngredient($egg);
        $this->persister->saveIngredient($pasta);

        // Act
        $actual = $this->ingredientRepository->findAll();

        // Assert
        RepasAssert::assertTabType(Tab::newEmptyTyped(Ingredient::class), $actual);
        $this->assertEqualsCanonicalizing([$egg->getSlug(), $pasta->getSlug()], $this->slugsOf($actual));
    }

    public function testFindAllReturnsNothingWithoutIngredient(): void
    {
        // Act
        $actual = $this->ingredientRepository->findAll();

        // Assert
        $this->assertCount(0, $actual);
    }

    public function testFindByOwnerReturnsOnlyTheOwnIngredients(): void
    {
        // Arrange
        $owner = new UserBuilder()->build();
        $other = new UserBuilder()->build();
        $own = new IngredientBuilder()->isPasta()->withCreator($owner, true)->build();
        $notOwn = new IngredientBuilder()->isMilk()->withCreator($other, true)->build();
        foreach ([$own, $notOwn] as $ingredient) {
            $this->persister->saveIngredient($ingredient);
        }

        // Act
        $actual = $this->ingredientRepository->findByOwner($owner->getId());

        // Assert
        RepasAssert::assertTabType(Tab::newEmptyTyped(Ingredient::class), $actual);
        $slugs = $this->slugsOf($actual);
        $this->assertSame([$own->getSlug()], $slugs);
        $this->assertContains($own->getSlug(), $slugs);
        $this->assertNotContains($notOwn->getSlug(), $slugs);
    }

    private function boolColumnOf(string $slug, string $column): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(sprintf('SELECT %s FROM ingredient WHERE slug = ?', $column), [$slug]);
    }

    private function columnOf(string $slug, string $column): ?string
    {
        $value = $this->entityManager->getConnection()->fetchOne(sprintf('SELECT %s FROM ingredient WHERE slug = ?', $column), [$slug]);

        return $value === false ? null : $value;
    }

    public function testExistsForCreatorSeesAnIngredientBySlug(): void
    {
        // Arrange
        $creator = new UserBuilder()->build();
        $this->persister->saveUser($creator);
        $butter = new IngredientBuilder()->isButter()->withCreator($creator, true)->build();
        $this->persister->saveIngredient($butter);

        // Act & Assert : le slug est pris, même pour un autre nom normalisé et un autre créateur
        $this->assertTrue($this->ingredientRepository->existsForCreator($butter->getSlug(), 'another-user-id', 'autre-nom'));
        $this->assertFalse($this->ingredientRepository->existsForCreator('libre', $creator->getId(), 'autre-nom'));
    }

    public function testExistsForCreatorSeesTheSameNormalizedNameOfTheCreatorWhateverTheSlug(): void
    {
        // Arrange : un ancien commun passé à son propriétaire (créateur posé, slug sans identifiant de compte)
        $owner = new UserBuilder()->build();
        $other = new UserBuilder()->build();
        $this->persister->saveUser($owner);
        $this->persister->saveUser($other);
        $tomato = new IngredientBuilder()->withName('Tomate')->withSlug('tomate')->withCreator($owner)->build();
        $this->persister->saveIngredient($tomato);
        $this->assertSame('tomate', $tomato->getSlug());

        // Act & Assert : le slug d'une création serait `tomate<identifiant>`, libre, mais le nom normalisé est déjà au créateur
        $this->assertFalse($this->ingredientRepository->exists('tomate' . $owner->getId()));
        $this->assertTrue($this->ingredientRepository->existsForCreator('tomate' . $owner->getId(), $owner->getId(), 'tomate'));
        // pas pour un autre créateur, ni pour un autre nom
        $this->assertFalse($this->ingredientRepository->existsForCreator('tomate' . $other->getId(), $other->getId(), 'tomate'));
        $this->assertFalse($this->ingredientRepository->existsForCreator('tomate-cerise' . $owner->getId(), $owner->getId(), 'tomate-cerise'));
    }

    public function testExistsForCreatorIgnoresACommonIngredientOfTheSameName(): void
    {
        // Arrange : avant la passation, « Tomate » est un commun (sans créateur) : il n'est à personne
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);
        $this->persister->saveIngredient(new IngredientBuilder()->withName('Tomate')->build());

        // Act & Assert
        $this->assertFalse($this->ingredientRepository->existsForCreator('tomate' . $user->getId(), $user->getId(), 'tomate'));
    }

    public function testExistsForCreatorCostsASingleQuery(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);
        $this->persister->saveIngredient(new IngredientBuilder()->withName('Tomate')->withCreator($user, true)->build());
        static::getContainer()->get('doctrine')->getManager()->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // Act
        $this->ingredientRepository->existsForCreator('tomate' . $user->getId(), $user->getId(), 'tomate');

        // Assert
        $this->assertSame(['SELECT ingredient' => 1], $counter->byTable());
    }

    /**
     * @param Tab<Ingredient> $ingredients
     * @return list<string>
     */
    private function slugsOf(Tab $ingredients): array
    {
        return array_values(array_map(static fn(Ingredient $ingredient): string => $ingredient->getSlug(), $ingredients->toArray()));
    }
}
