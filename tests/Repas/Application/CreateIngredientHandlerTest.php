<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Application\CreateIngredient\CreateIngredientHandler;
use Repas\Repas\Domain\Event\CreateIngredientWithConversionEvent;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\DepartmentInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class CreateIngredientHandlerTest extends TestCase
{
    private readonly CreateIngredientHandler $handler;
    private readonly UserRepository $userRepository;
    private readonly IngredientRepository $ingredientRepository;
    private readonly UnitRepository $unitRepository;
    private readonly DepartmentRepository $departmentRepository;
    private readonly EventDispatcherInterface $eventDispatcher;
    private readonly ConversionRepository $conversionRepository;

    protected function setUp(): void
    {
        $baby = new DepartmentBuilder()->isBaby()->build();
        $unite = new UnitBuilder()->isUnite()->build();
        $liter = new UnitBuilder()->isLiter()->build();
        $centiliter = new UnitBuilder()->isCentiliter()->build();

        $this->userRepository = new UserInMemoryRepository();
        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->unitRepository = new UnitInMemoryRepository([$unite, $liter, $centiliter]);
        $this->departmentRepository = new DepartmentInMemoryRepository([$baby]);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->conversionRepository = new ConversionInMemoryRepository([
            new ConversionBuilder()
                ->withStartUnit($liter)
                ->withEndUnit($centiliter)
                ->withCoefficient(100)
                ->withoutIngredient()
                ->build()
        ]);
        $conversionService = new ConversionService(
            $this->conversionRepository,
            $this->unitRepository,
        );

        $this->handler = new CreateIngredientHandler(
            $this->departmentRepository,
            $this->unitRepository,
            $this->ingredientRepository,
            $this->userRepository,
            $this->eventDispatcher,
            $conversionService
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
            coefficient: null,
        );

        // Assert
        $this->eventDispatcher->expects(self::never())->method('dispatch');

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

        $actualUser = $this->userRepository->findOneById($user->getId());
        $this->assertEquals(1, $actualUser->getIngredientStats());
    }

    public function testHandleSuccessfullyCreateIngredientWithDifferentUnitConvertible(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "bebe",
            defaultCookingUnitSlug: "litre",
            defaultPurchaseUnitSlug: "centilitre",
            ownerId: $user->getId(),
            coefficient: null,
        );

        // Assert
        $this->eventDispatcher->expects(self::never())->method('dispatch');

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new IngredientBuilder()
            ->withName("nom de l'ingredient")
            ->withImage("super-image.jpg")
            ->withDepartment(new DepartmentBuilder()->isBaby())
            ->withDefaultCookingUnit(new UnitBuilder()->isLiter())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isCentiliter())
            ->withCreator($user)
            ->build();
        $actual = $this->ingredientRepository->findOneBySlug($expected->getSlug());
        RepasAssert::assertIngredient($expected, $actual);

        $actualUser = $this->userRepository->findOneById($user->getId());
        $this->assertEquals(1, $actualUser->getIngredientStats());
    }

    public function testHandleSuccessfullyCreateIngredientWithDifferentUnitNotConvertible(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $this->userRepository->save($user);
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "bebe",
            defaultCookingUnitSlug: "unite",
            defaultPurchaseUnitSlug: "centilitre",
            ownerId: $user->getId(),
            coefficient: 25,
        );

        $expected = new IngredientBuilder()
            ->withName("nom de l'ingredient")
            ->withImage("super-image.jpg")
            ->withDepartment(new DepartmentBuilder()->isBaby())
            ->withDefaultCookingUnit(new UnitBuilder()->isUnite())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isCentiliter())
            ->withCompatibleUnits([
                new UnitBuilder()->isUnite(),
                new UnitBuilder()->isCentiliter(),
                new UnitBuilder()->isLiter(),
            ])
            ->withCreator($user)
            ->build();

        // Assert
        $this->eventDispatcher->expects(self::once())->method('dispatch')->with(new CreateIngredientWithConversionEvent(
            $expected->getSlug(),
            25
        ));

        // Act
        ($this->handler)($command);

        // Assert
        $actual = $this->ingredientRepository->findOneBySlug($expected->getSlug());
        RepasAssert::assertIngredient($expected, $actual);

        $actualUser = $this->userRepository->findOneById($user->getId());
        $this->assertEquals(1, $actualUser->getIngredientStats());
    }

    public function testHandleSuccessfullyCreateIngredientByAdmin(): void
    {
        // Arrange
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "bebe",
            defaultCookingUnitSlug: "unite",
            defaultPurchaseUnitSlug: "unite",
            ownerId: null,
            coefficient: null,
        );

        // Assert
        $this->eventDispatcher->expects(self::never())->method('dispatch');

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new IngredientBuilder()
            ->withName("nom de l'ingredient")
            ->withImage("super-image.jpg")
            ->withDepartment(new DepartmentBuilder()->isBaby())
            ->withDefaultCookingUnit(new UnitBuilder()->isUnite())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())
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
            coefficient: null,
        );

        // Assert
        $this->eventDispatcher->expects(self::never())->method('dispatch');
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
            coefficient: null,
        );

        // Assert
        $this->eventDispatcher->expects(self::never())->method('dispatch');
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
            coefficient: null,
        );

        // Assert
        $this->eventDispatcher->expects(self::never())->method('dispatch');
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
            coefficient: null,
        );

        // Assert
        $this->eventDispatcher->expects(self::never())->method('dispatch');
        $this->expectExceptionObject(UnitException::NotFound("inexistant"));

        // Act
        ($this->handler)($command);
    }
}
