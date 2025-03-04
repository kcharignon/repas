<?php

namespace Repas\Tests\Repas\Repository;


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
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

class IngredientRepositoryTest extends DatabaseTestCase
{
    private readonly IngredientRepository $ingredientRepository;
    private readonly UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ingredientRepository = static::getContainer()->get(IngredientRepository::class);
        $this->userRepository = static::getContainer()->get(UserRepository::class);
    }

    public function testCRUD(): void
    {
        // Arrange
        $ingredient = new IngredientBuilder()->build();

        // Act (insert)
        $this->ingredientRepository->save($ingredient);

        // Assert
        $loadedIngredient = $this->ingredientRepository->findOneBySlug($ingredient->getSlug());
        RepasAssert::assertIngredient($ingredient, $loadedIngredient);

        // Arrange
        $baby = new DepartmentBuilder()->isBaby()->build();
        $gramme = new UnitBuilder()->isGramme()->build();
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
            $this->userRepository->save($creator);
        }
        $this->userRepository->save($user);
        // Compte le nombre d'ingrédients au rayon bebe
        $babyDepartment = new DepartmentBuilder()->isBaby()->build();
        $before = $this->ingredientRepository->findByDepartmentAndOwner($babyDepartment, $user);
        $count = $before->count();
        // Ajoute un ingredient au rayon bébé
        $ingredient = new IngredientBuilder()
            ->onBabyDepartment()
            ->withCreator($creator)
            ->build();
        $this->ingredientRepository->save($ingredient);

        // Act
        $actual = $this->ingredientRepository->findByDepartmentAndOwner($babyDepartment, $user);

        // Assert
        $this->assertEquals($see ? ++$count : $count, $actual->count());
    }

    public function testFindAll(): void
    {
        // Act
        $actual = $this->ingredientRepository->findAll();

        // Assert
        $this->assertCount(256, $actual);
        RepasAssert::assertTabType(Tab::newEmptyTyped(Ingredient::class), $actual);
    }

    public function testFindByOwner(): void
    {
        // Act
        $actual = $this->ingredientRepository->findByOwner(new UserBuilder()->build());

        // Assert
        $this->assertCount(256, $actual);
        RepasAssert::assertTabType(Tab::newEmptyTyped(Ingredient::class), $actual);
    }
}
