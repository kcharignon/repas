<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\UpdateIngredient\UpdateIngredientCommand;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Application\Command\UpdateIngredient\UpdateIngredientHandler;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Ingredient;
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
use Repas\Tests\Helper\IngredientCatalogOwnerInMemory;
use Repas\Tests\Helper\RepasAssert;

class UpdateIngredientHandlerTest extends TestCase
{
    private readonly UpdateIngredientHandler $handler;
    private readonly DepartmentRepository $departmentRepository;
    private readonly UnitRepository $unitRepository;
    private readonly IngredientRepository $ingredientRepository;
    private readonly ConversionRepository $conversionRepository;
    private readonly IngredientCatalogOwnerInMemory $catalogOwner;

    protected function setUp(): void
    {
        $this->departmentRepository = new DepartmentInMemoryRepository([
            new DepartmentBuilder()->isConserve()->build(),
            new DepartmentBuilder()->isCereal()->build(),
            new DepartmentBuilder()->isMiscellaneous()->build(),
        ]);
        $this->unitRepository = new UnitInMemoryRepository([
            new UnitBuilder()->isGramme()->build(),
            new UnitBuilder()->isBox()->build(),
            new UnitBuilder()->isUnite()->build(),
        ]);
        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->conversionRepository = new ConversionInMemoryRepository();
        $this->catalogOwner = new IngredientCatalogOwnerInMemory('owner-id');

        $conversionService = new ConversionService(
            $this->conversionRepository,
            $this->unitRepository,
        );
        $this->handler = new UpdateIngredientHandler(
            $this->departmentRepository,
            $this->unitRepository,
            $this->ingredientRepository,
            $this->conversionRepository,
            $conversionService,
            $this->catalogOwner,
        );
    }

    public function testHandleSuccessfullyUpdateIngredientWithSameUnit(): void
    {
        // Arrange
        $creator = new UserBuilder()->build();
        $ingredient = new IngredientBuilder()->withCreator($creator)->isPasta()->build();
        $this->ingredientRepository->save($ingredient);
        $command = new UpdateIngredientCommand(
            $ingredient->getSlug(),
            'Pasta',
            'la-pasta-picture.jpeg',
            'conserve',
            'boite',
            'boite',
            null,
            'user-id',
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new IngredientBuilder()
            ->withSlug('pate')
            ->withName('Pasta')
            ->withImage('la-pasta-picture.jpeg')
            ->withDepartment(new DepartmentBuilder()->isConserve())
            ->withCreator($creator)
            ->withDefaultCookingUnit(new UnitBuilder()->isBox())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isBox())
            ->withCompatibleUnits([new UnitBuilder()->isBox()])
            ->build();

        $actual = $this->ingredientRepository->findOneBySlug($ingredient->getSlug());
        RepasAssert::assertIngredient($expected, $actual);
    }

    public function testHandleSuccessfullyUpdateIngredientWithUpdateConversion(): void
    {
        // Arrange
        // On creer un ingredient oeuf qui s'achète par boite de 6
        $ingredient = new IngredientBuilder()
            ->isEgg()
            ->withDefaultPurchaseUnit(new UnitBuilder()->isBox())
            ->build();
        $this->ingredientRepository->save($ingredient);
        // La conversion entre la boite et les oeufs à l'unité
        $conversion = new ConversionBuilder()
            ->withIngredient($ingredient)
            ->withCoefficient(6)
            ->withStartUnit(new UnitBuilder()->isBox())
            ->withEndUnit(new UnitBuilder()->isUnite())
            ->build();
        $this->conversionRepository->save($conversion);
        $command = new UpdateIngredientCommand(
            $ingredient->getSlug(),
            'oeuf de caille',
            'oeuf-de-caille.jpeg',
            'divers',
            'gramme',
            'unite',
            60,
            'user-id',
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new IngredientBuilder()
            ->withSlug('oeuf')
            ->withName('oeuf de caille')
            ->withImage('oeuf-de-caille.jpeg')
            ->withDepartment(new DepartmentBuilder()->isMiscellaneous())
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())
            ->withCompatibleUnits([new UnitBuilder()->isGramme(), new UnitBuilder()->isUnite()])
            ->build();

        $actual = $this->ingredientRepository->findOneBySlug($ingredient->getSlug());
        RepasAssert::assertIngredient($expected, $actual);

        $expectedConversion = new ConversionBuilder()
            ->withIngredient($ingredient)
            ->withCoefficient(60)
            ->withStartUnit(new UnitBuilder()->isUnite())
            ->withEndUnit(new UnitBuilder()->isGramme())
            ->build();
        $actualConversion = $this->conversionRepository->findByIngredientAndStartUnitAndEndUnit($expected, $expected->getDefaultPurchaseUnit(), $expected->getDefaultCookingUnit());
        RepasAssert::assertConversion($expectedConversion, $actualConversion, ['id', 'ingredient']);
    }

    public function testHandleSuccessfullyUpdateIngredientWithCreateConversion(): void
    {
        // Arrange
        // On creer un ingredient oeuf qui s'achète par boite de 6
        $ingredient = new IngredientBuilder()->isEgg()->build();
        $this->ingredientRepository->save($ingredient);
        $command = new UpdateIngredientCommand(
            $ingredient->getSlug(),
            'oeuf de caille',
            'oeuf-de-caille.jpeg',
            'divers',
            'gramme',
            'unite',
            60,
            'user-id',
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new IngredientBuilder()
            ->withSlug('oeuf')
            ->withName('oeuf de caille')
            ->withImage('oeuf-de-caille.jpeg')
            ->withDepartment(new DepartmentBuilder()->isMiscellaneous())
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())
            ->withCompatibleUnits([new UnitBuilder()->isGramme(), new UnitBuilder()->isUnite()])
            ->build();

        $actual = $this->ingredientRepository->findOneBySlug($ingredient->getSlug());
        RepasAssert::assertIngredient($expected, $actual);

        $expectedConversion = new ConversionBuilder()
            ->withIngredient($ingredient)
            ->withCoefficient(60)
            ->withStartUnit(new UnitBuilder()->isUnite())
            ->withEndUnit(new UnitBuilder()->isGramme())
            ->build();
        $actualConversion = $this->conversionRepository->findByIngredientAndStartUnitAndEndUnit($expected, $expected->getDefaultPurchaseUnit(), $expected->getDefaultCookingUnit());
        RepasAssert::assertConversion($expectedConversion, $actualConversion, ['id', 'ingredient']);
    }

    public function testKeepsTheImageWhenNoneIsGiven(): void
    {
        // Arrange
        $ingredient = new IngredientBuilder()->isPasta()->withImage('images/pate.png')->build();
        $this->ingredientRepository->save($ingredient);
        $command = new UpdateIngredientCommand($ingredient->getSlug(), 'Pasta', null, 'conserve', 'boite', 'boite', null, 'user-id');

        // Act
        ($this->handler)($command);

        // Assert
        $this->assertSame('images/pate.png', $this->ingredientRepository->findOneBySlug($ingredient->getSlug())->getImage());
    }

    public function testRequiresACoefficientWhenTheUnitsDiffer(): void
    {
        // Arrange
        $ingredient = new IngredientBuilder()->isPasta()->build();
        $this->ingredientRepository->save($ingredient);
        $command = new UpdateIngredientCommand($ingredient->getSlug(), 'Pasta', null, 'conserve', 'gramme', 'boite', null, 'user-id');

        // Assert
        $this->expectExceptionObject(IngredientException::coefficientRequired());

        // Act
        ($this->handler)($command);
    }

    public function testHandleFailedUpdateIngredientUnknownIngredient(): void
    {
        // Arrange
        $ingredient = new IngredientBuilder()->isEgg()->build();
        $command = new UpdateIngredientCommand(
            $ingredient->getSlug(),
            'oeuf de caille',
            'oeuf-de-caille.jpeg',
            'divers',
            'gramme',
            'unite',
            60,
            'user-id',
        );

        // Assert
        $this->expectExceptionObject(IngredientException::notFound('oeuf'));

        // Act
        ($this->handler)($command);
    }

    /**
     * Un ingrédient de pâtes à `$creatorId` (`null` : un ancien commun), marqué ou non, enregistré.
     */
    private function savedPasta(?string $creatorId, bool $isBase = false): Ingredient
    {
        $ingredient = new IngredientBuilder()->isPasta()->withCreator($creatorId === null ? null : new UserBuilder()->withId($creatorId)->build(), true)->withBase($isBase)->build();
        $this->ingredientRepository->save($ingredient);

        return $ingredient;
    }

    /**
     * Un `PUT` qui ne change que le nom (les mêmes unités, aucune conversion), avec le marqueur demandé (`null` : absent du corps).
     */
    private function rename(Ingredient $ingredient, string $userId, ?bool $isBase): UpdateIngredientCommand
    {
        return new UpdateIngredientCommand($ingredient->getSlug(), 'Pasta', null, 'conserve', 'gramme', 'gramme', null, $userId, $isBase);
    }

    public function testTheCatalogOwnerMarksItsIngredientAsBase(): void
    {
        // Given
        $ingredient = $this->savedPasta('owner-id');

        // When
        ($this->handler)($this->rename($ingredient, 'owner-id', true));

        // Then : le marqueur est posé, avec le reste de la modification
        $updated = $this->ingredientRepository->findOneBySlug($ingredient->getSlug());
        $this->assertTrue($updated->isBase());
        $this->assertSame('Pasta', $updated->getName());
        $this->assertSame(1, $this->catalogOwner->calls);
    }

    public function testTheCatalogOwnerRemovesTheMarker(): void
    {
        // Given
        $ingredient = $this->savedPasta('owner-id', isBase: true);

        // When
        ($this->handler)($this->rename($ingredient, 'owner-id', false));

        // Then
        $this->assertFalse($this->ingredientRepository->findOneBySlug($ingredient->getSlug())->isBase());
    }

    public function testTheCatalogOwnerMarksAnOldCommonIngredient(): void
    {
        // Given : jusqu'à ING-014, les anciens communs (sans créateur) existent encore ; le propriétaire du catalogue est administrateur
        $common = $this->savedPasta(null);

        // When
        ($this->handler)($this->rename($common, 'owner-id', true));

        // Then
        $this->assertTrue($this->ingredientRepository->findOneBySlug($common->getSlug())->isBase());
    }

    public function testWithoutTheFieldTheMarkerIsNeverReset(): void
    {
        // Given : un ancien client ne connaît pas le champ ; il ne doit jamais remettre le marqueur à zéro, que ce soit le propriétaire du
        // catalogue ou n'importe quel créateur d'un ingrédient marqué
        $ownersIngredient = $this->savedPasta('owner-id', isBase: true);

        // When
        ($this->handler)($this->rename($ownersIngredient, 'owner-id', null));

        // Then
        $this->assertTrue($this->ingredientRepository->findOneBySlug($ownersIngredient->getSlug())->isBase());
        $this->assertSame('Pasta', $this->ingredientRepository->findOneBySlug($ownersIngredient->getSlug())->getName());
        $this->assertSame(0, $this->catalogOwner->calls, 'Absent, le marqueur ne coûte aucune lecture du propriétaire du catalogue.');
    }

    public function testTheSameValueIsAcceptedFromAnyCreatorWithoutReadingTheCatalogOwner(): void
    {
        // Given : un utilisateur ordinaire renvoie ce qu'il a lu (faux) ; un marqueur déjà posé (inerte, ailleurs que chez le propriétaire) aussi
        $notBase = $this->savedPasta('user-id');

        // When
        ($this->handler)($this->rename($notBase, 'user-id', false));

        // Then
        $this->assertFalse($this->ingredientRepository->findOneBySlug($notBase->getSlug())->isBase());
        $this->assertSame(0, $this->catalogOwner->calls);
    }

    public function testAnOrdinaryUserCannotMarkItsIngredientAndNothingIsWritten(): void
    {
        // Given : un PUT qui changerait aussi les unités, donc créerait une conversion
        $ingredient = $this->savedPasta('user-id');
        $command = new UpdateIngredientCommand($ingredient->getSlug(), 'Pasta', 'new.png', 'conserve', 'gramme', 'boite', 500.0, 'user-id', true);

        // Then
        $this->expectExceptionObject(IngredientException::baseMarkerForbidden());

        // When
        try {
            ($this->handler)($command);
        } finally {
            $unchanged = $this->ingredientRepository->findOneBySlug($ingredient->getSlug());
            $this->assertFalse($unchanged->isBase());
            $this->assertSame('pate', $unchanged->getName());
            $this->assertNull($this->conversionRepository->findByIngredientAndStartUnitAndEndUnit($unchanged, new UnitBuilder()->isBox()->build(), new UnitBuilder()->isGramme()->build()));
        }
    }

    public function testAnOrdinaryUserCannotRemoveAMarkerEither(): void
    {
        // Given : un marqueur posé ailleurs que chez le propriétaire (par un défaut ou un accès direct à la base)
        $ingredient = $this->savedPasta('user-id', isBase: true);

        // Then : « différent de l'actuel » suffit au refus, dans les deux sens
        $this->expectExceptionObject(IngredientException::baseMarkerForbidden());

        // When
        try {
            ($this->handler)($this->rename($ingredient, 'user-id', false));
        } finally {
            $this->assertTrue($this->ingredientRepository->findOneBySlug($ingredient->getSlug())->isBase());
        }
    }

    public function testAnAdministratorWhoIsNotTheCatalogOwnerCannotMarkAnIngredient(): void
    {
        // Given : un administrateur modifie les anciens communs (passe-droit), mais ne pose pas le marqueur
        $common = $this->savedPasta(null);

        // Then
        $this->expectExceptionObject(IngredientException::baseMarkerForbidden());

        // When
        try {
            ($this->handler)($this->rename($common, 'admin-id', true));
        } finally {
            $this->assertFalse($this->ingredientRepository->findOneBySlug($common->getSlug())->isBase());
        }
    }

    public function testTheCatalogOwnerCannotMarkTheIngredientOfAnotherUser(): void
    {
        // Given
        $theirs = $this->savedPasta('user-id');

        // Then : un marqueur n'a de sens que dans le catalogue du propriétaire
        $this->expectExceptionObject(IngredientException::baseMarkerForbidden());

        // When
        ($this->handler)($this->rename($theirs, 'owner-id', true));
    }

    public function testWithoutACatalogOwnerNobodyCanMarkAnIngredient(): void
    {
        // Given : le compte propriétaire n'existe pas
        $ingredient = $this->savedPasta('owner-id');
        $handler = new UpdateIngredientHandler(
            $this->departmentRepository,
            $this->unitRepository,
            $this->ingredientRepository,
            $this->conversionRepository,
            new ConversionService($this->conversionRepository, $this->unitRepository),
            new IngredientCatalogOwnerInMemory(null),
        );

        // Then
        $this->expectExceptionObject(IngredientException::baseMarkerForbidden());

        // When
        $handler($this->rename($ingredient, 'owner-id', true));
    }
}
