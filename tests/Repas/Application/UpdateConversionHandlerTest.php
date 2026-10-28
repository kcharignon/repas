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
use Repas\Repas\Domain\Model\Conversion;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
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
        // Arrange : une conversion universelle garde sa paire et reste universelle, seul son coefficient se corrige
        $id = 'unique-id-without-ingredient';
        $liter = new UnitBuilder()->isLiter()->build();
        $centiliter = new UnitBuilder()->isCentiliter()->build();
        $command = new UpdateConversionCommand(
            id: $id,
            startUnitSlug: $liter->getSlug(),
            endUnitSlug: $centiliter->getSlug(),
            coefficient: 99,
            ingredientSlug: null,
            userId: self::USER,
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new ConversionBuilder()
            ->withId($id)
            ->withStartUnit($liter)
            ->withEndUnit($centiliter)
            ->withCoefficient(99)
            ->withoutIngredient()
            ->build();
        $actual = $this->conversionRepository->findById($id);
        RepasAssert::assertConversion($expected, $actual);
    }

    public function testAUniversalConversionNeverBecomesTheConversionOfAnIngredient(): void
    {
        // Given : « litre > centilitre » universelle, et l'administrateur veut la rattacher à l'œuf (le sien)
        $liter = new UnitBuilder()->isLiter()->build();
        $centiliter = new UnitBuilder()->isCentiliter()->build();
        $egg = new IngredientBuilder()->isEgg()->build();

        // When / Then : refusée, rien n'a bougé
        try {
            ($this->handler)(new UpdateConversionCommand('unique-id-without-ingredient', $liter->getSlug(), $centiliter->getSlug(), 100, $egg->getSlug(), self::USER));
            $this->fail('Une conversion universelle ne devient pas propre à un ingrédient.');
        } catch (ConversionException $e) {
            $this->assertSame(ConversionException::UNIVERSAL_CONVERSION_IS_FIXED, $e->getMessage());
            $this->assertSame(422, $e->getCode());
        }
        $this->assertNull($this->conversionRepository->findById('unique-id-without-ingredient')->getIngredient());
    }

    public function testAUniversalConversionKeepsItsPairOfUnits(): void
    {
        // Given : « litre > centilitre » universelle ; changer sa paire, dans un sens, dans l'autre ou pour d'autres unités, la supprimerait
        $liter = new UnitBuilder()->isLiter()->build();
        $centiliter = new UnitBuilder()->isCentiliter()->build();
        $box = new UnitBuilder()->isBox()->build();

        foreach ([[$centiliter, $liter, 0.01], [$box, $centiliter, 100], [$liter, $box, 100]] as [$start, $end, $coefficient]) {
            // When / Then
            try {
                ($this->handler)(new UpdateConversionCommand('unique-id-without-ingredient', $start->getSlug(), $end->getSlug(), $coefficient, null, self::USER));
                $this->fail('Une conversion universelle garde sa paire d\'unités.');
            } catch (ConversionException $e) {
                $this->assertSame(ConversionException::UNIVERSAL_CONVERSION_IS_FIXED, $e->getMessage());
            }
        }

        $conversion = $this->conversionRepository->findById('unique-id-without-ingredient');
        $this->assertSame('litre', $conversion->getStartUnit()->getSlug());
        $this->assertSame('centilitre', $conversion->getEndUnit()->getSlug());
        $this->assertEquals(100, $conversion->getCoefficient());
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
        // Given : une conversion de l'administrateur sur SON ingrédient, qu'il voudrait rattacher à l'œuf d'un autre
        $box = new UnitBuilder()->isBox()->build();
        $unite = new UnitBuilder()->isUnite()->build();
        $egg = new IngredientBuilder()->isEgg()->build();
        $sugar = new IngredientBuilder()->isSugar()->withCreator(new UserBuilder()->withId(self::ANOTHER_ADMIN))->build();
        $this->ingredientRepository->save($sugar);
        $this->conversionRepository->save(new ConversionBuilder()->withId('sugar-conversion')->withIngredient($sugar)->withStartUnit($box)->withEndUnit($unite)->withCoefficient(2)->build());

        // When
        try {
            ($this->handler)(new UpdateConversionCommand('sugar-conversion', $box->getSlug(), $unite->getSlug(), 6, $egg->getSlug(), self::ANOTHER_ADMIN));
            $this->fail('L\'ingrédient d\'un autre est inconnu, même pour un administrateur.');
        } catch (IngredientException $exception) {
            // Then
            $this->assertEquals(IngredientException::notFound($egg->getSlug()), $exception);
            $this->assertSame($sugar->getSlug(), $this->conversionRepository->findById('sugar-conversion')->getIngredient()->getSlug());
        }
    }

    public function testAConversionIsNotAttachedToAnIngredientThatHasTheMaximumButStaysEditableOnItsOwn(): void
    {
        // Given : le sucre de l'administrateur a ses 50 conversions propres ; l'œuf, une (celle du jeu de test)
        $box = new UnitBuilder()->isBox()->build();
        $unite = new UnitBuilder()->isUnite()->build();
        $sugar = new IngredientBuilder()->isSugar()->build();
        $this->ingredientRepository->save($sugar);
        for ($i = 1; $i <= Conversion::MAX_PER_INGREDIENT; ++$i) {
            $this->conversionRepository->save(new ConversionBuilder()->withId("filler-$i")->withIngredient($sugar)->withStartUnit($box)->withEndUnit($unite)->withCoefficient($i)->build());
        }

        // When : on rattache la conversion de l'œuf au sucre
        try {
            ($this->handler)(new UpdateConversionCommand('unique-id-with-ingredient', $box->getSlug(), $unite->getSlug(), 6, $sugar->getSlug(), self::USER));
            $this->fail('Le sucre a déjà ses 50 conversions propres.');
        } catch (ConversionException $exception) {
            // Then : 422, et la conversion reste à l'œuf
            $this->assertEquals(ConversionException::limitReached(), $exception);
            $this->assertSame('Œuf', $this->conversionRepository->findById('unique-id-with-ingredient')->getIngredient()->getName());
        }

        // When : une conversion du sucre, elle, se corrige (elle n'est pas rattachée à un autre ingrédient)
        ($this->handler)(new UpdateConversionCommand('filler-3', $box->getSlug(), $unite->getSlug(), 9, $sugar->getSlug(), self::USER));

        // Then
        $this->assertSame(9.0, $this->conversionRepository->findById('filler-3')->getCoefficient());
    }

    public function testAnAdministratorCanUpdateAGeneralConversion(): void
    {
        // Given : « litre > centilitre », la conversion générale de l'ensemble de test
        $liter = new UnitBuilder()->isLiter()->build();
        $centiliter = new UnitBuilder()->isCentiliter()->build();

        // When : un autre administrateur en corrige le coefficient (il n'a de passe-droit que sur les conversions générales)
        ($this->handler)(new UpdateConversionCommand('unique-id-without-ingredient', $liter->getSlug(), $centiliter->getSlug(), 6, null, self::ANOTHER_ADMIN));

        // Then
        $this->assertSame(6.0, $this->conversionRepository->findById('unique-id-without-ingredient')->getCoefficient());
    }
}
