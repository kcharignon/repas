<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\UpdateIngredient\UpdateIngredientCommand;
use Repas\Repas\Application\UpdateIngredient\UpdateIngredientHandler;
use Repas\Repas\Domain\Exception\IngredientException;
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
use Repas\Tests\Helper\RepasAssert;

class UpdateIngredientHandlerTest extends TestCase
{
    private readonly UpdateIngredientHandler $handler;
    private readonly DepartmentRepository $departmentRepository;
    private readonly UnitRepository $unitRepository;
    private readonly IngredientRepository $ingredientRepository;
    private readonly ConversionRepository $conversionRepository;


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
        );

        // Assert
        $this->expectExceptionObject(IngredientException::notFound('oeuf'));

        // Act
        ($this->handler)($command);
    }
}
