<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\Attributes\DataProvider;
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
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Shared\Domain\Tool\StringTool;
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
use Repas\Tests\Helper\IngredientCatalogOwnerInMemory;
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
    private readonly IngredientCatalogOwnerInMemory $catalogOwner;

    protected function setUp(): void
    {
        $baby = new DepartmentBuilder()->isBaby()->build();
        $unite = new UnitBuilder()->isUnite()->build();
        $liter = new UnitBuilder()->isLiter()->build();
        $centiliter = new UnitBuilder()->isCentiliter()->build();

        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->aliasRepository = new IngredientAliasInMemoryRepository();
        $this->catalogOwner = new IngredientCatalogOwnerInMemory('owner-id');
        $this->unitRepository = new UnitInMemoryRepository([$unite, $liter, $centiliter]);
        $this->departmentRepository = new DepartmentInMemoryRepository([$baby, new DepartmentBuilder()->isAnimal()->build(), new DepartmentBuilder()->isConserve()->build()]);
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
            $conversionService,
            $this->catalogOwner,
            new IngredientSlugPolicy(),
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
            ->withName("Nom de l'ingredient")
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
            ->withName("Nom de l'ingredient")
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
            coefficient: 25,
        );

        $expected = new IngredientBuilder()
            ->withName("Nom de l'ingredient")
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
            new CreateIngredientWithConversionEvent($expected->getSlug(), 25, $expected->getCreatorId()),
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
            coefficient: null,
        );

        // Act
        ($this->handler)($command);

        // Assert : l'ingrédient d'un administrateur est à lui, comme celui de tout le monde, et son compteur « ingrédients créés » le suit
        $this->assertEquals([new IngredientCreatedEvent($admin->getId(), 'nom-de-l-ingredientadmin-id')], $this->eventDispatcher->eventDispatched);
        $expected = new IngredientBuilder()
            ->withName("Nom de l'ingredient")
            ->withImage("super-image.jpg")
            ->withDepartment(new DepartmentBuilder()->isBaby())
            ->withDefaultCookingUnit(new UnitBuilder()->isUnite())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())
            ->withCreator($admin)
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
            coefficient: null,
        );

        // Act
        $slug = ($this->handler)($command);

        // Assert
        $this->assertSame('nom-de-l-ingredientuser-id', $slug);
        $this->assertSame($slug, $this->ingredientRepository->findOneBySlug($slug)->getSlug());
    }

    public function testAnAdministratorNeverCreatesACommonIngredient(): void
    {
        // Arrange
        $admin = new UserBuilder()->withId('admin-id')->isAdmin()->build();
        $command = new CreateIngredientCommand("nom de l'ingredient", '', 'bebe', 'unite', 'unite', $admin->getId(), null);

        // Act
        $slug = ($this->handler)($command);

        // Assert : même slug (nom et identifiant) et même créateur que pour un utilisateur ordinaire
        $this->assertSame('admin-id', $this->ingredientRepository->findOneBySlug($slug)->getCreatorId());
        $this->assertSame('nom-de-l-ingredientadmin-id', $slug);
    }

    public function testNoCreationEverLeavesAnIngredientWithoutCreator(): void
    {
        // Act
        foreach (['user-id', 'admin-id', 'owner-id'] as $userId) {
            ($this->handler)($this->createCommand($userId));
        }

        // Assert
        foreach ($this->ingredientRepository->findAll() as $ingredient) {
            $this->assertNotNull($ingredient->getCreatorId());
        }
        $this->assertCount(3, $this->ingredientRepository->findAll());
    }

    public function testTwoAccountsCreatingTheSameNameHaveTwoDistinctIngredients(): void
    {
        // Act
        $first = ($this->handler)($this->createCommand('user-id'));
        $second = ($this->handler)($this->createCommand('admin-id'));

        // Assert
        $this->assertNotSame($first, $second);
        $this->assertSame('user-id', $this->ingredientRepository->findOneBySlug($first)->getCreatorId());
        $this->assertSame('admin-id', $this->ingredientRepository->findOneBySlug($second)->getCreatorId());
    }

    public function testRefusesTheSameNormalizedNameForTheSameCreator(): void
    {
        // Arrange : « Tomate » chez le propriétaire, avec un slug SANS identifiant (un ancien commun passé à son propriétaire par la passation)
        $this->ingredientRepository->save($this->legacyTomato(new UserBuilder()->withId('owner-id')->build()));

        // Assert : le slug du nouvel ingrédient (tomateowner-id) est libre, mais le nom normalisé est déjà à lui
        $this->expectExceptionObject(IngredientException::nameAlreadyUsedInDepartment());

        // Act
        ($this->handler)(new CreateIngredientCommand('TOMATE', '', 'bebe', 'unite', 'unite', 'owner-id', null));
    }

    public function testAnOldCommonIngredientDoesNotBelongToTheAdministratorBeforeTheHandover(): void
    {
        // Arrange : avant la passation, « Tomate » est un commun (sans créateur)
        $this->ingredientRepository->save($this->legacyTomato(null));

        // Act : le doublon est permis, il sera proposé par « Doublons possibles »
        $slug = ($this->handler)(new CreateIngredientCommand('Tomate', '', 'bebe', 'unite', 'unite', 'owner-id', null));

        // Assert
        $this->assertSame('tomateowner-id', $slug);
        $this->assertSame('tomate', $this->ingredientRepository->findOneBySlug('tomate')->getSlug());
    }

    public function testTheSameNameInAnotherDepartmentIsAllowedAndEachGetsItsOwnSlug(): void
    {
        // Act : « Petits pois » dans trois rayons chez la même personne (ADR 0028, amendement du 2026-10-30)
        $first = ($this->handler)(new CreateIngredientCommand('Petits pois', '', 'bebe', 'unite', 'unite', 'user-id', null));
        $second = ($this->handler)(new CreateIngredientCommand('Petits pois', '', 'animal', 'unite', 'unite', 'user-id', null));
        $third = ($this->handler)(new CreateIngredientCommand('petits pois', '', 'conserve', 'unite', 'unite', 'user-id', null));

        // Assert : trois identifiants distincts, le premier est celui d'avant (rien ne change pour l'existant), chacun dans son rayon
        $this->assertSame('petits-poisuser-id', $first);
        $this->assertSame('petits-pois-2user-id', $second);
        $this->assertSame('petits-pois-3user-id', $third);
        $this->assertSame('bebe', $this->ingredientRepository->findOneBySlug($first)->getDepartment()->getSlug());
        $this->assertSame('animal', $this->ingredientRepository->findOneBySlug($second)->getDepartment()->getSlug());
        $this->assertSame('conserve', $this->ingredientRepository->findOneBySlug($third)->getDepartment()->getSlug());
        foreach ([$first, $second, $third] as $slug) {
            $this->assertSame('petits-pois', $this->ingredientRepository->findOneBySlug($slug)->getSluggedName());
            $this->assertSame('user-id', $this->ingredientRepository->findOneBySlug($slug)->getCreatorId());
        }
        $this->assertCount(3, $this->eventDispatcher->eventDispatched);
    }

    public function testTheSameNameIsRefusedInTheDepartmentThatAlreadyHasItEvenWhenAnotherDepartmentHasItToo(): void
    {
        // Arrange : « Petits pois » dans deux rayons
        ($this->handler)(new CreateIngredientCommand('Petits pois', '', 'bebe', 'unite', 'unite', 'user-id', null));
        ($this->handler)(new CreateIngredientCommand('Petits pois', '', 'animal', 'unite', 'unite', 'user-id', null));

        // Act et Assert : chacun des deux rayons refuse un troisième, sans rien écrire
        foreach (['bebe', 'animal'] as $department) {
            try {
                ($this->handler)(new CreateIngredientCommand('PETITS POIS', '', $department, 'unite', 'unite', 'user-id', null));
                $this->fail("Le rayon $department aurait dû refuser le doublon.");
            } catch (IngredientException $exception) {
                $this->assertEquals(IngredientException::nameAlreadyUsedInDepartment(), $exception);
                $this->assertSame(409, $exception->getCode());
            }
        }
        $this->assertCount(2, $this->ingredientRepository->findAll());
        $this->assertCount(2, $this->eventDispatcher->eventDispatched);
    }

    public function testTheSameNameInTheSameDepartmentIsAllowedForAnotherCreator(): void
    {
        // Arrange
        ($this->handler)(new CreateIngredientCommand('Petits pois', '', 'bebe', 'unite', 'unite', 'user-id', null));

        // Act
        $slug = ($this->handler)(new CreateIngredientCommand('Petits pois', '', 'bebe', 'unite', 'unite', 'other-id', null));

        // Assert : chacun chez soi, le slug de l'autre porte son identifiant
        $this->assertSame('petits-poisother-id', $slug);
        $this->assertCount(2, $this->ingredientRepository->findAll());
    }

    public function testARenamedIngredientFreesItsNameButKeepsItsSlugSoTheNewOneGetsAnotherSlug(): void
    {
        // Arrange : « Tomate » est renommé « Poire » : son slug reste celui du premier nom
        $first = ($this->handler)(new CreateIngredientCommand('Tomate', '', 'bebe', 'unite', 'unite', 'user-id', null));
        $renamed = $this->ingredientRepository->findOneBySlug($first);
        $renamed->update('Poire', '', $renamed->getDepartment(), $renamed->getDefaultCookingUnit(), $renamed->getDefaultPurchaseUnit(), $renamed->getCompatibleUnits());
        $this->ingredientRepository->save($renamed);

        // Act : « Tomate » est de nouveau libre dans ce rayon
        $second = ($this->handler)(new CreateIngredientCommand('Tomate', '', 'bebe', 'unite', 'unite', 'user-id', null));

        // Assert : ni refus ni écrasement de l'ingrédient renommé
        $this->assertSame('tomateuser-id', $first);
        $this->assertSame('tomate-2user-id', $second);
        $this->assertSame('Poire', $this->ingredientRepository->findOneBySlug($first)->getName());
        $this->assertSame('Tomate', $this->ingredientRepository->findOneBySlug($second)->getName());
    }

    public function testRefusesAnIngredientThatAlreadyExists(): void
    {
        // Arrange
        $user = new UserBuilder()->withId('user-id')->build();
        $command = new CreateIngredientCommand("nom de l'ingredient", '', 'bebe', 'unite', 'unite', $user->getId(), null);
        ($this->handler)($command);

        // Assert : un second ingrédient de même nom, même rayon et même créateur est un doublon
        $this->expectExceptionObject(IngredientException::nameAlreadyUsedInDepartment());

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
        ($this->handler)(new CreateIngredientCommand("nom de l'ingredient", '', 'bebe', 'unite', 'unite', 'user-id', null));

        // Assert : l'alias du créateur est défait, celui d'un autre utilisateur n'est pas touché
        $this->assertNull($this->aliasRepository->find('user-id', 'nom-de-l-ingredientuser-id'));
        $this->assertNotNull($this->aliasRepository->find('other-id', 'nom-de-l-ingredientuser-id'));
    }

    public function testAnAlreadyExistingIngredientKeepsTheAlias(): void
    {
        // Arrange
        $command = new CreateIngredientCommand("nom de l'ingredient", '', 'bebe', 'unite', 'unite', 'user-id', null);
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

    public function testAnAdministratorCreatingAnIngredientOnlyTouchesItsOwnAliases(): void
    {
        // Arrange : l'alias d'un utilisateur dont la clé est le slug d'un ancien commun (« nom-de-l-ingredient »), et un alias de l'administrateur dont la clé est
        // le slug qu'il va créer
        $this->aliasRepository->save(IngredientAlias::create('user-id', 'nom-de-l-ingredient', 'nom-de-l-ingredientuser-id', AliasKind::ADOPTION, 'first-use', new \DateTimeImmutable()));
        $this->aliasRepository->save(IngredientAlias::create('admin-id', 'nom-de-l-ingredientadmin-id', 'autre-ingredient', AliasKind::MERGE, 'merge', new \DateTimeImmutable()));

        // Act
        ($this->handler)(new CreateIngredientCommand("nom de l'ingredient", '', 'bebe', 'unite', 'unite', 'admin-id', null));

        // Assert
        $this->assertNotNull($this->aliasRepository->find('user-id', 'nom-de-l-ingredient'));
        $this->assertNull($this->aliasRepository->find('admin-id', 'nom-de-l-ingredientadmin-id'));
    }

    public function testRequiresACoefficientWhenTheUnitsCannotBeConverted(): void
    {
        // Arrange
        $user = new UserBuilder()->withId('user-id')->build();
        $command = new CreateIngredientCommand("nom de l'ingredient", '', 'bebe', 'unite', 'centilitre', $user->getId(), null);

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

    private function legacyTomato(?\Repas\User\Domain\Model\User $creator): \Repas\Repas\Domain\Model\Ingredient
    {
        // Un ancien commun a un slug sans identifiant de compte, y compris une fois passé à son propriétaire
        return new IngredientBuilder()->withName('Tomate')->withDepartment(new DepartmentBuilder()->isBaby())
            ->withDefaultCookingUnit(new UnitBuilder()->isUnite())->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())->withCreator($creator)->build();
    }

    private function createCommand(string $userId, bool $isBase = false): CreateIngredientCommand
    {
        return new CreateIngredientCommand("nom de l'ingredient", '', 'bebe', 'unite', 'unite', $userId, null, $isBase);
    }

    public function testAnIngredientIsNotBaseByDefaultAndTheCatalogOwnerIsNotRead(): void
    {
        // Given
        $command = new CreateIngredientCommand("nom de l'ingredient", '', 'bebe', 'unite', 'unite', 'user-id', null);

        // When
        $slug = ($this->handler)($command);

        // Then : naître à faux ne demande l'avis de personne, donc aucune requête de plus
        $this->assertFalse($this->ingredientRepository->findOneBySlug($slug)->isBase());
        $this->assertSame(0, $this->catalogOwner->calls);
    }

    public function testNotAskingForTheMarkerIsAcceptedFromAnyUserWithoutReadingTheCatalogOwner(): void
    {
        // When : `false` explicite, d'un utilisateur ordinaire comme d'un administrateur qui n'est pas le propriétaire
        $ordinary = ($this->handler)($this->createCommand('user-id', false));
        $admin = ($this->handler)($this->createCommand('admin-id', false));

        // Then
        $this->assertFalse($this->ingredientRepository->findOneBySlug($ordinary)->isBase());
        $this->assertFalse($this->ingredientRepository->findOneBySlug($admin)->isBase());
        $this->assertSame(0, $this->catalogOwner->calls);
    }

    public function testTheCatalogOwnerCreatesABaseIngredientOfItsOwn(): void
    {
        // When
        $slug = ($this->handler)($this->createCommand('owner-id', true));

        // Then
        $ingredient = $this->ingredientRepository->findOneBySlug($slug);
        $this->assertTrue($ingredient->isBase());
        $this->assertSame('owner-id', $ingredient->getCreatorId());
        $this->assertSame(1, $this->catalogOwner->calls);
        $this->assertEquals([new IngredientCreatedEvent('owner-id', $slug)], $this->eventDispatcher->eventDispatched);
    }


    public function testAnotherUserCannotCreateABaseIngredientAndNothingIsWritten(): void
    {
        // Given : une liaison d'alias qui serait défaite par la création, pour prouver qu'elle n'a pas eu lieu
        $this->aliasRepository->save(IngredientAlias::create('user-id', 'nom-de-l-ingredientuser-id', 'autre-ingredient', AliasKind::MERGE, 'merge', new \DateTimeImmutable()));

        // Then
        $this->expectExceptionObject(IngredientException::baseMarkerForbidden());

        // When
        try {
            ($this->handler)($this->createCommand('user-id', true));
        } finally {
            $this->assertFalse($this->ingredientRepository->exists('nom-de-l-ingredientuser-id'));
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
            $this->assertNotNull($this->aliasRepository->find('user-id', 'nom-de-l-ingredientuser-id'));
        }
    }

    public function testAnAdministratorWhoIsNotTheCatalogOwnerCannotCreateABaseIngredientEither(): void
    {
        // Then : un administrateur n'est pas le propriétaire du catalogue (D1)
        $this->expectExceptionObject(IngredientException::baseMarkerForbidden());

        // When
        try {
            ($this->handler)($this->createCommand('admin-id', true));
        } finally {
            $this->assertFalse($this->ingredientRepository->exists('nom-de-l-ingredient'));
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testWithoutACatalogOwnerNobodyCanCreateABaseIngredient(): void
    {
        // Given : le compte propriétaire n'existe pas (base neuve, développement)
        $handler = new CreateIngredientHandler(
            $this->departmentRepository,
            $this->unitRepository,
            $this->ingredientRepository,
            $this->aliasRepository,
            $this->eventDispatcher,
            new ConversionService($this->conversionRepository, $this->unitRepository),
            new IngredientCatalogOwnerInMemory(null),
            new IngredientSlugPolicy(),
        );

        // Then
        $this->expectExceptionObject(IngredientException::baseMarkerForbidden());

        // When
        $handler($this->createCommand('owner-id', true));
    }

    public function testACreatorWhoHasTheMaximumNumberOfIngredientsCannotCreateAnother(): void
    {
        // Arrange : 2 000 ingrédients à lui (copies et créations confondues), un à un autre
        $user = new UserBuilder()->withId('user-id')->build();
        for ($i = 0; $i < Ingredient::MAX_PER_CREATOR; $i++) {
            $this->ingredientRepository->save(new IngredientBuilder()->isPasta()->withSlug("vrac-$i")->withCreator($user)->build());
        }
        $this->ingredientRepository->save(new IngredientBuilder()->isPasta()->withSlug('vrac-other')->withCreator(new UserBuilder()->withId('other-id')->build())->build());
        $command = new CreateIngredientCommand('Un de trop', 'image.jpg', 'bebe', 'unite', 'unite', $user->getId(), null);

        // Assert
        $this->expectExceptionObject(IngredientException::limitReached());

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
            $this->assertFalse($this->ingredientRepository->exists('un-de-trop' . $user->getId()));
        }
    }

    public function testACreatorBelowTheMaximumStillCreatesTheLastIngredient(): void
    {
        // Arrange : 1 999 ingrédients à lui
        $user = new UserBuilder()->withId('user-id')->build();
        for ($i = 0; $i < Ingredient::MAX_PER_CREATOR - 1; $i++) {
            $this->ingredientRepository->save(new IngredientBuilder()->isPasta()->withSlug("vrac-$i")->withCreator($user)->build());
        }

        // Act
        ($this->handler)(new CreateIngredientCommand('Le dernier', 'image.jpg', 'bebe', 'unite', 'unite', $user->getId(), null));

        // Assert
        $this->assertTrue($this->ingredientRepository->exists('le-dernieruser-id'));
    }

    /**
     * @return array<string, array{string, string}> le nom envoyé, le nom enregistré
     */
    public static function nameDataProvider(): array
    {
        return [
            'minuscules' => ['praliné', 'Praliné'],
            'plusieurs mots, la suite est conservée' => ['de la farine T55', 'De la farine T55'],
            'majuscule déjà là' => ['Praliné', 'Praliné'],
            'majuscule déjà là, suite en majuscules' => ['Lait de COCO', 'Lait de COCO'],
            'accent' => ['éclair', 'Éclair'],
            'ligature' => ['œufs', 'Œufs'],
            'ligature déjà en majuscule' => ['Œufs', 'Œufs'],
            'chiffre' => ['00 farine', '00 farine'],
            'parenthèse' => ['(bio) tomate', '(bio) tomate'],
            'apostrophe' => ["'sel", "'sel"],
        ];
    }

    #[DataProvider('nameDataProvider')]
    public function testTheFirstLetterOfTheNameIsCapitalizedWithoutAnyErrorAndTheRestIsKept(string $sent, string $stored): void
    {
        // Arrange
        $command = new CreateIngredientCommand($sent, '', 'bebe', 'unite', 'unite', 'user-id', null);

        // Act
        $slug = ($this->handler)($command);

        // Assert : le nom enregistré est le nom corrigé ; le slug et le nom normalisé ne dépendent pas de la casse
        $ingredient = $this->ingredientRepository->findOneBySlug($slug);
        $this->assertSame($stored, $ingredient->getName());
        $this->assertSame(StringTool::slugify($sent . 'user-id'), $slug);
        $this->assertSame(StringTool::slugify($sent), $ingredient->getSluggedName());
    }

    public function testTheSameNameWithADifferentCaseIsStillADuplicateForTheSameCreator(): void
    {
        // Arrange : « Praliné » existe déjà chez l'utilisateur
        ($this->handler)(new CreateIngredientCommand('Praliné', '', 'bebe', 'unite', 'unite', 'user-id', null));

        // Assert : « praliné » (corrigé en « Praliné ») donne le même slug : la détection du doublon ne change pas
        $this->expectExceptionObject(IngredientException::nameAlreadyUsedInDepartment());

        // Act
        ($this->handler)(new CreateIngredientCommand('praliné', '', 'bebe', 'unite', 'unite', 'user-id', null));
    }
}
