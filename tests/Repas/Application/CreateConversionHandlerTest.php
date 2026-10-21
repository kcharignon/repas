<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CreateConversion\CreateConversionCommand;
use Repas\Repas\Application\Command\CreateConversion\CreateConversionHandler;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\ControlledUuidGenerator;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class CreateConversionHandlerTest extends TestCase
{
    /** L'administrateur qui crée la conversion : le propriétaire des ingrédients de test par défaut. */
    private const string USER = IngredientBuilder::DEFAULT_CREATOR_ID;
    private const string ANOTHER_ADMIN = 'another-admin-id';

    private CreateConversionHandler $handler;
    private ConversionRepository $conversionRepository;
    private UnitRepository $unitRepository;
    private IngredientRepository $ingredientRepository;

    protected function setUp(): void
    {
        $this->conversionRepository = new ConversionInMemoryRepository();
        $this->unitRepository = new UnitInMemoryRepository();
        $this->ingredientRepository = new IngredientInMemoryRepository();

        $this->handler = new CreateConversionHandler(
            $this->conversionRepository,
            $this->unitRepository,
            $this->ingredientRepository,
        );
    }

    public function testHandleSuccessfullyCreateConversionWithIngredient(): void
    {
        // Arrange
        $unite = new UnitBuilder()->isUnite()->build();
        $this->unitRepository->save($unite);
        $box = new UnitBuilder()->isBox()->build();
        $this->unitRepository->save($box);
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->ingredientRepository->save($egg);

        $command = new CreateConversionCommand(ControlledUuidGenerator::UUID, $box->getSlug(), $unite->getSlug(), 6, $egg->getSlug(), self::USER);

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ConversionBuilder()
            ->withId(ControlledUuidGenerator::UUID)
            ->withStartUnit($box)
            ->withEndUnit($unite)
            ->withCoefficient(6)
            ->withIngredient($egg)
            ->build();
        $actual = $this->conversionRepository->findByIngredientOrCommon($egg)->reset();
        RepasAssert::assertConversion($expected, $actual);
    }


    public function testHandleSuccessfullyCreateConversionWithoutIngredient(): void
    {
        // Arrange
        $unite = new UnitBuilder()->isUnite()->build();
        $this->unitRepository->save($unite);
        $box = new UnitBuilder()->isBox()->build();
        $this->unitRepository->save($box);

        $command = new CreateConversionCommand(ControlledUuidGenerator::UUID, $box->getSlug(), $unite->getSlug(), 6, null, self::USER);

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ConversionBuilder()
            ->withId(ControlledUuidGenerator::UUID)
            ->withStartUnit($box)
            ->withEndUnit($unite)
            ->withCoefficient(6)
            ->withoutIngredient()
            ->build();
        $actual = $this->conversionRepository->findAll()->reset();
        RepasAssert::assertConversion($expected, $actual);
    }

    public function testAnAdministratorCannotCreateAConversionOnTheIngredientOfAnotherUser(): void
    {
        // Given : pas de passe-droit d'administrateur sur les ingrédients des autres (ADR 0028, amendement, D4)
        $unite = new UnitBuilder()->isUnite()->build();
        $this->unitRepository->save($unite);
        $box = new UnitBuilder()->isBox()->build();
        $this->unitRepository->save($box);
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->ingredientRepository->save($egg);

        $command = new CreateConversionCommand(ControlledUuidGenerator::UUID, $box->getSlug(), $unite->getSlug(), 6, $egg->getSlug(), self::ANOTHER_ADMIN);

        // When
        try {
            ($this->handler)($command);
            $this->fail("L'ingrédient d'un autre est inconnu, même pour un administrateur.");
        } catch (IngredientException $exception) {
            // Then : 404, et rien n'est écrit
            $this->assertEquals(IngredientException::notFound($egg->getSlug()), $exception);
            $this->assertCount(0, $this->conversionRepository->findAll());
        }
    }

    public function testAnAdministratorCanCreateAGeneralConversion(): void
    {
        // Given : une conversion générale n'a pas de propriétaire, n'importe quel administrateur en crée
        $unite = new UnitBuilder()->isUnite()->build();
        $this->unitRepository->save($unite);
        $box = new UnitBuilder()->isBox()->build();
        $this->unitRepository->save($box);

        // When
        ($this->handler)(new CreateConversionCommand(ControlledUuidGenerator::UUID, $box->getSlug(), $unite->getSlug(), 6, null, self::ANOTHER_ADMIN));

        // Then
        $this->assertCount(1, $this->conversionRepository->findAll());
    }

    public function testHandleFailedCreateConversionUnknownIngredient(): void
    {
        // Arrange
        $unite = new UnitBuilder()->isUnite()->build();
        $this->unitRepository->save($unite);
        $box = new UnitBuilder()->isBox()->build();
        $this->unitRepository->save($box);
        $egg = new IngredientBuilder()->isEgg()->build();

        $command = new CreateConversionCommand(ControlledUuidGenerator::UUID, $box->getSlug(), $unite->getSlug(), 6, $egg->getSlug(), self::USER);

        // Assert
        $this->expectExceptionObject(IngredientException::notFound($egg->getSlug()));

        // Act
        ($this->handler)($command);
    }

    public function testHandleFailedCreateConversionUnknownStartUnit(): void
    {
        // Arrange
        $unite = new UnitBuilder()->isUnite()->build();
        $this->unitRepository->save($unite);
        $box = new UnitBuilder()->isBox()->build();
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->ingredientRepository->save($egg);

        $command = new CreateConversionCommand(ControlledUuidGenerator::UUID, $box->getSlug(), $unite->getSlug(), 6, $egg->getSlug(), self::USER);

        // Assert
        $this->expectExceptionObject(UnitException::notFound($box->getSlug()));

        // Act
        ($this->handler)($command);
    }

    public function testHandleFailedCreateConversionUnknownEndUnit(): void
    {
        // Arrange
        $unite = new UnitBuilder()->isUnite()->build();
        $box = new UnitBuilder()->isBox()->build();
        $this->unitRepository->save($box);
        $egg = new IngredientBuilder()->isEgg()->build();
        $this->ingredientRepository->save($egg);

        $command = new CreateConversionCommand(ControlledUuidGenerator::UUID, $box->getSlug(), $unite->getSlug(), 6, $egg->getSlug(), self::USER);

        // Assert
        $this->expectExceptionObject(UnitException::notFound($unite->getSlug()));

        // Act
        ($this->handler)($command);
    }
}
