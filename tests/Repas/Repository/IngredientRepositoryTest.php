<?php

namespace Repas\Tests\Repas\Repository;


use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Tests\Builder\DepartmentBuilder;
use Repas\Tests\Builder\IngredientBuilder;
use Repas\Tests\Builder\UnitBuilder;
use Repas\Tests\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
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

        // Act
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

        // Act
        $this->ingredientRepository->save($ingredient);

        // Assert
        $loadedIngredient = $this->ingredientRepository->findOneBySlug($ingredient->getSlug());
        RepasAssert::assertIngredient($ingredient, $loadedIngredient);
    }

    public function getByDepartmentDataProvider(): array
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

    /**
     * @dataProvider getByDepartmentDataProvider
     */
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
}
