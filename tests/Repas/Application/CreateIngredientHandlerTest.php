<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Application\CreateIngredient\CreateIngredientHandler;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Tests\Builder\DepartmentBuilder;
use Repas\Tests\Builder\IngredientBuilder;
use Repas\Tests\Builder\UnitBuilder;
use Repas\Tests\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\DepartmentInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

class CreateIngredientHandlerTest extends TestCase
{
    private readonly CreateIngredientHandler $handler;
    private readonly UserRepository $userRepository;
    private readonly IngredientRepository $ingredientRepository;
    private readonly UnitRepository $unitRepository;
    private readonly DepartmentRepository $departmentRepository;

    protected function setUp(): void
    {
        $baby = new DepartmentBuilder()->isBaby()->build();
        $unite = new UnitBuilder()->isUnite()->build();

        $this->userRepository = new UserInMemoryRepository();
        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->unitRepository = new UnitInMemoryRepository([$unite]);
        $this->departmentRepository = new DepartmentInMemoryRepository([$baby]);

        $this->handler = new CreateIngredientHandler(
            $this->departmentRepository,
            $this->unitRepository,
            $this->ingredientRepository,
            $this->userRepository,
        );
    }

    public function testHandleSuccessfullyCreateIngredient(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "bebe",
            defaultCookingUnitSlug: "unite",
            defaultPurchaseUnitSlug: "unite",
            ownerId: $user->getId(),
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new IngredientBuilder()
            ->withName("nom de l'ingredient")
            ->withImage("super-image.jpg")
            ->withDepartment(new DepartmentBuilder()->isBaby())
            ->withDefaultCookingUnit(new UnitBuilder()->isUnite())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())
            ->withCreator($user)
            ->build();
        $actual = $this->ingredientRepository->findOneBySlug($expected->getSlug());
        RepasAssert::assertIngredient($expected, $actual);
    }


    public function testHandleCreateIngredientFailedUserNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "bebe",
            defaultCookingUnitSlug: "unite",
            defaultPurchaseUnitSlug: "unite",
            ownerId: $user->getId(),
        );

        // Assert
        $this->expectExceptionObject(UserException::NotFound($user->getId()));

        // Act
        ($this->handler)($command);
    }

    public function testHandleCreateIngredientFailedDepartmentNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "inexistant",
            defaultCookingUnitSlug: "unite",
            defaultPurchaseUnitSlug: "unite",
            ownerId: $user->getId(),
        );

        // Assert
        $this->expectExceptionObject(DepartmentException::NotFound());

        // Act
        ($this->handler)($command);
    }

    public function testHandleCreateIngredientFailedDefaultCookingUnitNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "bebe",
            defaultCookingUnitSlug: "inexistant",
            defaultPurchaseUnitSlug: "unite",
            ownerId: $user->getId(),
        );

        // Assert
        $this->expectExceptionObject(UnitException::NotFound("inexistant"));

        // Act
        ($this->handler)($command);
    }

    public function testHandleCreateIngredientFailedDefaultPurchaseUnitNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "bebe",
            defaultCookingUnitSlug: "unite",
            defaultPurchaseUnitSlug: "inexistant",
            ownerId: $user->getId(),
        );

        // Assert
        $this->expectExceptionObject(UnitException::NotFound("inexistant"));

        // Act
        ($this->handler)($command);
    }
}
