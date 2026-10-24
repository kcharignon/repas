<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\UpdateConversion\UpdateConversionCommand;
use Repas\Repas\Application\Command\UpdateConversion\UpdateConversionHandler;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class UpdateConversionHandlerTest extends TestCase
{
    /** L'administrateur qui modifie la conversion : le propriétaire des ingrédients de test par défaut. */
    private const string USER = IngredientBuilder::DEFAULT_CREATOR_ID;
    private const string ANOTHER_ADMIN = 'another-admin-id';

    private UpdateConversionHandler $handler;
    private ConversionRepository $conversionRepository;
    private UnitRepository $unitRepository;
    private IngredientRepository $ingredientRepository;

    protected function setUp(): void
    {
        $liter = new UnitBuilder()->isLiter()->build();
        $centiliter = new UnitBuilder()->isCentiliter()->build();
        $this->conversionRepository = new ConversionInMemoryRepository([
            new ConversionBuilder()
                ->withId("unique-id-with-ingredient")
                ->isUnitToGrammeForEgg()
                ->build(),
            new ConversionBuilder()
                ->withId("unique-id-without-ingredient")
                ->withStartUnit($liter)
                ->withEndUnit($centiliter)
                ->withCoefficient(100)
                ->withoutIngredient()
                ->build(),
            new ConversionBuilder()->build()
        ]);
        $this->unitRepository = new UnitInMemoryRepository([
            new UnitBuilder()->isUnite()->build(),
            new UnitBuilder()->isGramme()->build(),
            new UnitBuilder()->isBox()->build(),
            $liter,
            $centiliter,
        ]);
        $this->ingredientRepository = new IngredientInMemoryRepository([
            new IngredientBuilder()->isEgg()->build(),
        ]);

        $this->handler = new UpdateConversionHandler(
            $this->conversionRepository,
            $this->unitRepository,
            $this->ingredientRepository,
        );
    }

    public function testHandleSuccessfullyUpdateConversionWithIngredient(): void
    {
        // Arrange
        $id = 'unique-id-with-ingredient';
        $box = new UnitBuilder()->isBox()->build();
        $unite = new UnitBuilder()->isUnite()->build();
        $egg = new IngredientBuilder()->isEgg()->build();
        $command = new UpdateConversionCommand(
            id: $id,
            startUnitSlug: $box->getSlug(),
            endUnitSlug: $unite->getSlug(),
            coefficient: 6,
            ingredientSlug: $egg->getSlug(),
            userId: self::USER,
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ConversionBuilder()
            ->withId($id)
            ->withStartUnit($box)
            ->withEndUnit($unite)
            ->withCoefficient(6)
            ->withIngredient($egg)
            ->build();
        $actual = $this->conversionRepository->findById($id);
        RepasAssert::assertConversion($expected, $actual);
    }


    public function testHandleSuccessfullyUpdateConversionRemoveIngredient(): void
    {
        // Arrange
        $id = 'unique-id-with-ingredient';
        $box = new UnitBuilder()->isBox()->build();
        $unite = new UnitBuilder()->isUnite()->build();
        $command = new UpdateConversionCommand(
            id: $id,
            startUnitSlug: $box->getSlug(),
            endUnitSlug: $unite->getSlug(),
            coefficient: 6,
            ingredientSlug: null,
            userId: self::USER,
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ConversionBuilder()
            ->withId($id)
            ->withStartUnit($box)
            ->withEndUnit($unite)
            ->withCoefficient(6)
            ->withoutIngredient()
            ->build();
        $actual = $this->conversionRepository->findById($id);
        RepasAssert::assertConversion($expected, $actual);
    }

    public function testHandleSuccessfullyUpdateConversionWithoutIngredient(): void
    {
        // Arrange
        $id = 'unique-id-without-ingredient';
        $box = new UnitBuilder()->isBox()->build();
        $unite = new UnitBuilder()->isUnite()->build();
        $command = new UpdateConversionCommand(
            id: $id,
            startUnitSlug: $box->getSlug(),
            endUnitSlug: $unite->getSlug(),
            coefficient: 6,
            ingredientSlug: null,
            userId: self::USER,
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ConversionBuilder()
            ->withId($id)
            ->withStartUnit($box)
            ->withEndUnit($unite)
            ->withCoefficient(6)
            ->withoutIngredient()
            ->build();
        $actual = $this->conversionRepository->findById($id);
        RepasAssert::assertConversion($expected, $actual);
    }

    public function testHandleSuccessfullyUpdateConversionAddIngredient(): void
    {
        // Arrange
        $id = 'unique-id-without-ingredient';
        $box = new UnitBuilder()->isBox()->build();
        $unite = new UnitBuilder()->isUnite()->build();
        $egg = new IngredientBuilder()->isEgg()->build();
        $command = new UpdateConversionCommand(
            id: $id,
            startUnitSlug: $box->getSlug(),
            endUnitSlug: $unite->getSlug(),
            coefficient: 6,
            ingredientSlug: $egg->getSlug(),
            userId: self::USER,
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ConversionBuilder()
            ->withId($id)
            ->withStartUnit($box)
            ->withEndUnit($unite)
            ->withCoefficient(6)
            ->withIngredient($egg)
            ->build();
        $actual = $this->conversionRepository->findById($id);
        RepasAssert::assertConversion($expected, $actual);
    }

    public function testAnAdministratorCannotUpdateTheConversionOfAnIngredientOfAnotherUser(): void
    {
        // Given : la conversion est celle de l'ingrédient d'un autre : elle est inconnue pour cet administrateur (D4)
        $box = new UnitBuilder()->isBox()->build();
        $unite = new UnitBuilder()->isUnite()->build();

        // When
        try {
            ($this->handler)(new UpdateConversionCommand('unique-id-with-ingredient', $box->getSlug(), $unite->getSlug(), 6, null, self::ANOTHER_ADMIN));
            $this->fail('La conversion de l\'ingrédient d\'un autre est inconnue, même pour un administrateur.');
        } catch (ConversionException $exception) {
            // Then : 404 et rien n'a changé
            $this->assertEquals(ConversionException::notFound('unique-id-with-ingredient'), $exception);
            $this->assertSame(60.0, $this->conversionRepository->findById('unique-id-with-ingredient')->getCoefficient());
        }
    }

    public function testAnAdministratorCannotAttachAConversionToTheIngredientOfAnotherUser(): void
    {
        // Given : une conversion générale que l'administrateur voudrait rattacher à l'ingrédient d'un autre
        $box = new UnitBuilder()->isBox()->build();
        $unite = new UnitBuilder()->isUnite()->build();
        $egg = new IngredientBuilder()->isEgg()->build();

        // When
        try {
            ($this->handler)(new UpdateConversionCommand('unique-id-without-ingredient', $box->getSlug(), $unite->getSlug(), 6, $egg->getSlug(), self::ANOTHER_ADMIN));
            $this->fail('L\'ingrédient d\'un autre est inconnu, même pour un administrateur.');
        } catch (IngredientException $exception) {
            // Then
            $this->assertEquals(IngredientException::notFound($egg->getSlug()), $exception);
            $this->assertNull($this->conversionRepository->findById('unique-id-without-ingredient')->getIngredient());
        }
    }

    public function testAnAdministratorCanUpdateAGeneralConversion(): void
    {
        // Given
        $box = new UnitBuilder()->isBox()->build();
        $unite = new UnitBuilder()->isUnite()->build();

        // When
        ($this->handler)(new UpdateConversionCommand('unique-id-without-ingredient', $box->getSlug(), $unite->getSlug(), 6, null, self::ANOTHER_ADMIN));

        // Then
        $this->assertSame(6.0, $this->conversionRepository->findById('unique-id-without-ingredient')->getCoefficient());
    }
}
