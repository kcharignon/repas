<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientHandler;
use Repas\Repas\Domain\Event\CreateIngredientWithConversionEvent;
use Repas\Shared\Domain\Event\IngredientCreatedEvent;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\DepartmentInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;

class CreateIngredientHandlerTest extends TestCase
{
    private readonly CreateIngredientHandler $handler;
    private readonly IngredientRepository $ingredientRepository;
    private readonly UnitRepository $unitRepository;
    private readonly DepartmentRepository $departmentRepository;
    private readonly SpyEventDispatcher $eventDispatcher;
    private readonly ConversionRepository $conversionRepository;
    private readonly IngredientAliasInMemoryRepository $aliasRepository;

    protected function setUp(): void
    {
        $baby = new DepartmentBuilder()->isBaby()->build();
        $unite = new UnitBuilder()->isUnite()->build();
        $liter = new UnitBuilder()->isLiter()->build();
        $centiliter = new UnitBuilder()->isCentiliter()->build();

        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->aliasRepository = new IngredientAliasInMemoryRepository();
        $this->unitRepository = new UnitInMemoryRepository([$unite, $liter, $centiliter]);
        $this->departmentRepository = new DepartmentInMemoryRepository([$baby]);
        $this->eventDispatcher = new SpyEventDispatcher();
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
            $this->aliasRepository,
            $this->eventDispatcher,
            $conversionService
        );
    }

    public function testHandleSuccessfullyCreateIngredient(): void
    {
        // Arrange
        $user = new UserBuilder()->withId('user-id')->build();
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "bebe",
            defaultCookingUnitSlug: "unite",
            defaultPurchaseUnitSlug: "unite",
            userId: $user->getId(),
            isAdmin: false,
            coefficient: null,
        );

        // Act
        ($this->handler)($command);

        // Assert
        $this->assertEquals([new IngredientCreatedEvent(
            $user->getId(),
            'nom-de-l-ingredientuser-id',
        )], $this->eventDispatcher->eventDispatched);
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

    public function testHandleSuccessfullyCreateIngredientWithDifferentUnitConvertible(): void
    {
        // Arrange
        $user = new UserBuilder()->withId('user-id')->build();
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "bebe",
            defaultCookingUnitSlug: "litre",
            defaultPurchaseUnitSlug: "centilitre",
            userId: $user->getId(),
            isAdmin: false,
            coefficient: null,
        );

        // Act
        ($this->handler)($command);

        // Assert
        $this->assertEquals([new IngredientCreatedEvent(
            $user->getId(),
            'nom-de-l-ingredientuser-id',
        )], $this->eventDispatcher->eventDispatched);
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
    }

    public function testHandleSuccessfullyCreateIngredientWithDifferentUnitNotConvertible(): void
    {
        // Arrange
        $user = new UserBuilder()->withId('user-id')->build();
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "bebe",
            defaultCookingUnitSlug: "unite",
            defaultPurchaseUnitSlug: "centilitre",
            userId: $user->getId(),
            isAdmin: false,
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

        // Act
        ($this->handler)($command);

        // Assert : les deux événements, dans l'ordre
        $this->assertEquals([
            new IngredientCreatedEvent($user->getId(), 'nom-de-l-ingredientuser-id'),
            new CreateIngredientWithConversionEvent($expected->getSlug(), 25),
        ], $this->eventDispatcher->eventDispatched);
        $actual = $this->ingredientRepository->findOneBySlug($expected->getSlug());
        RepasAssert::assertIngredient($expected, $actual);
    }

    public function testHandleSuccessfullyCreateIngredientByAdmin(): void
    {
        // Arrange
        $admin = new UserBuilder()->withId('admin-id')->isAdmin()->build();
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "bebe",
            defaultCookingUnitSlug: "unite",
            defaultPurchaseUnitSlug: "unite",
            userId: $admin->getId(),
            isAdmin: true,
            coefficient: null,
        );

        // Assert

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }

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


    public function testReturnsTheSlugOfTheCreatedIngredient(): void
    {
        // Arrange
        $user = new UserBuilder()->withId('user-id')->build();
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: '',
            departmentSlug: 'bebe',
            defaultCookingUnitSlug: 'unite',
            defaultPurchaseUnitSlug: 'unite',
            userId: $user->getId(),
            isAdmin: false,
            coefficient: null,
        );

        // Act
        $slug = ($this->handler)($command);

        // Assert
        $this->assertSame('nom-de-l-ingredientuser-id', $slug);
        $this->assertSame($slug, $this->ingredientRepository->findOneBySlug($slug)->getSlug());
    }

    public function testAnAdministratorCreatesACommonIngredient(): void
    {
        // Arrange
        $admin = new UserBuilder()->withId('admin-id')->isAdmin()->build();
        $command = new CreateIngredientCommand("nom de l'ingredient", '', 'bebe', 'unite', 'unite', $admin->getId(), true, null);

        // Act
        $slug = ($this->handler)($command);

        // Assert
        $this->assertNull($this->ingredientRepository->findOneBySlug($slug)->getCreatorId());
        $this->assertSame('nom-de-l-ingredient', $slug);
    }

    public function testRefusesAnIngredientThatAlreadyExists(): void
    {
        // Arrange
        $user = new UserBuilder()->withId('user-id')->build();
        $command = new CreateIngredientCommand("nom de l'ingredient", '', 'bebe', 'unite', 'unite', $user->getId(), false, null);
        ($this->handler)($command);

        // Assert : un second ingrédient de même nom et même créateur écraserait le premier
        $this->expectExceptionObject(IngredientException::alreadyExists('nom-de-l-ingredientuser-id'));

        // Act
        ($this->handler)($command);
    }

    public function testCreatingAnIngredientRemovesTheAliasOfTheUserWhoseKeyIsItsSlug(): void
    {
        // Arrange : un ingrédient absorbé par une fusion laisse un alias qui porte son ancien slug
        $staleAlias = IngredientAlias::create('user-id', 'nom-de-l-ingredientuser-id', 'autre-ingredient', AliasKind::MERGE, 'merge', new \DateTimeImmutable());
        $otherUserAlias = IngredientAlias::create('other-id', 'nom-de-l-ingredientuser-id', 'autre-ingredient', AliasKind::MERGE, 'merge', new \DateTimeImmutable());
        $this->aliasRepository->save($staleAlias);
        $this->aliasRepository->save($otherUserAlias);

        // Act
        ($this->handler)(new CreateIngredientCommand("nom de l'ingredient", '', 'bebe', 'unite', 'unite', 'user-id', false, null));

        // Assert : l'alias du créateur est défait, celui d'un autre utilisateur n'est pas touché
        $this->assertNull($this->aliasRepository->find('user-id', 'nom-de-l-ingredientuser-id'));
        $this->assertNotNull($this->aliasRepository->find('other-id', 'nom-de-l-ingredientuser-id'));
    }

    public function testAnAlreadyExistingIngredientKeepsTheAlias(): void
    {
        // Arrange
        $command = new CreateIngredientCommand("nom de l'ingredient", '', 'bebe', 'unite', 'unite', 'user-id', false, null);
        ($this->handler)($command);
        $this->aliasRepository->save(IngredientAlias::create('user-id', 'nom-de-l-ingredientuser-id', 'autre-ingredient', AliasKind::MERGE, 'merge', new \DateTimeImmutable()));

        // Act
        try {
            ($this->handler)($command);
            $this->fail('Le second ingrédient de même slug devait être refusé.');
        } catch (IngredientException) {
        }

        // Assert : le refus (409) ne touche à rien
        $this->assertNotNull($this->aliasRepository->find('user-id', 'nom-de-l-ingredientuser-id'));
    }

    public function testAnAdministratorCreatingACommonDoesNotTouchAliases(): void
    {
        // Arrange : un alias dont la clé est le slug du commun qu'un administrateur va créer
        $this->aliasRepository->save(IngredientAlias::create('user-id', 'nom-de-l-ingredient', 'nom-de-l-ingredientuser-id', AliasKind::ADOPTION, 'first-use', new \DateTimeImmutable()));

        // Act
        ($this->handler)(new CreateIngredientCommand("nom de l'ingredient", '', 'bebe', 'unite', 'unite', 'admin-id', true, null));

        // Assert
        $this->assertNotNull($this->aliasRepository->find('user-id', 'nom-de-l-ingredient'));
    }

    public function testRequiresACoefficientWhenTheUnitsCannotBeConverted(): void
    {
        // Arrange
        $user = new UserBuilder()->withId('user-id')->build();
        $command = new CreateIngredientCommand("nom de l'ingredient", '', 'bebe', 'unite', 'centilitre', $user->getId(), false, null);

        // Assert
        $this->expectExceptionObject(IngredientException::coefficientRequired());

        // Act
        ($this->handler)($command);
    }

    public function testHandleCreateIngredientFailedDepartmentNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "inexistant",
            defaultCookingUnitSlug: "unite",
            defaultPurchaseUnitSlug: "unite",
            userId: $user->getId(),
            isAdmin: false,
            coefficient: null,
        );

        // Assert
        $this->expectExceptionObject(DepartmentException::NotFound());

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testHandleCreateIngredientFailedDefaultCookingUnitNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "bebe",
            defaultCookingUnitSlug: "inexistant",
            defaultPurchaseUnitSlug: "unite",
            userId: $user->getId(),
            isAdmin: false,
            coefficient: null,
        );

        // Assert
        $this->expectExceptionObject(UnitException::NotFound("inexistant"));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testHandleCreateIngredientFailedDefaultPurchaseUnitNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $command = new CreateIngredientCommand(
            name: "nom de l'ingredient",
            image: "super-image.jpg",
            departmentSlug:  "bebe",
            defaultCookingUnitSlug: "unite",
            defaultPurchaseUnitSlug: "inexistant",
            userId: $user->getId(),
            isAdmin: false,
            coefficient: null,
        );

        // Assert
        $this->expectExceptionObject(UnitException::NotFound("inexistant"));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }
}
