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

    public static function getByDepartmentDataProvider(): array
    {
        $creator = new UserBuilder()->build();
        $admin = new UserBuilder()->isAdmin()->build();
        $otherUser = new UserBuilder()->build();

        return [
            'with creator and called by admin' => [$creator, $admin, false],
            'with creator and called by other user' => [$creator, $otherUser, false],
            'with creator and called by himself' => [$creator, $creator, true],
            'without creator and called by user' => [null, $otherUser, true],
            'without creator and called admin' => [null, $admin, true],
        ];
    }

    #[DataProvider('getByDepartmentDataProvider')]
    public function testGetByDepartment(?User $creator, User $user, bool $see): void
    {
        // Arrange
        if ($creator) {
            $this->persister->saveUser($creator);
        }
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

    public function testFindByOwnerReturnsSystemIngredientsAndTheOwnOnes(): void
    {
        // Arrange
        $owner = new UserBuilder()->build();
        $other = new UserBuilder()->build();
        $system = new IngredientBuilder()->isEgg()->build();
        $own = new IngredientBuilder()->isPasta()->withCreator($owner, true)->build();
        $notOwn = new IngredientBuilder()->isMilk()->withCreator($other, true)->build();
        foreach ([$system, $own, $notOwn] as $ingredient) {
            $this->persister->saveIngredient($ingredient);
        }

        // Act
        $actual = $this->ingredientRepository->findByOwner($owner->getId());

        // Assert
        RepasAssert::assertTabType(Tab::newEmptyTyped(Ingredient::class), $actual);
        $slugs = $this->slugsOf($actual);
        $this->assertContains($system->getSlug(), $slugs);
        $this->assertContains($own->getSlug(), $slugs);
        $this->assertNotContains($notOwn->getSlug(), $slugs);
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
