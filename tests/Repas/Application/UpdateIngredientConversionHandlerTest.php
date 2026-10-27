<?php

namespace Repas\Tests\Repas\Application;


use Repas\Repas\Application\Command\UpdateIngredientConversion\UpdateIngredientConversionCommand;
use Repas\Repas\Application\Command\UpdateIngredientConversion\UpdateIngredientConversionHandler;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;

class UpdateIngredientConversionHandlerTest extends IngredientConversionHandlerTestCase
{
    private UpdateIngredientConversionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new UpdateIngredientConversionHandler($this->ingredientRepository, $this->conversionRepository, $this->slugResolver, $this->ownConversions);
    }

    private function command(string $id = 'spoon-to-gramme', string $start = 'cuillere-a-soupe', string $end = 'gramme', float $coefficient = 12, ?string $userId = null): UpdateIngredientConversionCommand
    {
        return new UpdateIngredientConversionCommand($id, $this->flour->getSlug(), $start, $end, $coefficient, $userId ?? $this->user->getId());
    }

    public function testChangesTheCoefficientOfAnOwnConversion(): void
    {
        // When
        ($this->handler)($this->command());

        // Then
        $this->assertSame(12.0, $this->conversionRepository->findById('spoon-to-gramme')->getCoefficient());
    }

    public function testChangesTheUnitsOfAnOwnConversionWhileTheRecipeUnitStillConverts(): void
    {
        // Given : une seconde voie existe déjà entre la cuillère et le gramme, la tasse est à part
        $this->conversionRepository->save(new ConversionBuilder()->withId('cup')->withIngredient($this->flour)->withStartUnit($this->cup)->withEndUnit($this->gramme)->withCoefficient(125)->build());

        // When : la tasse se relie au verre au lieu du gramme (la cuillère garde son chemin vers le kilo)
        ($this->handler)($this->command('cup', 'tasse', 'verre', 2));

        // Then
        $changed = $this->conversionRepository->findById('cup');
        $this->assertSame(['tasse', 'verre', 2.0], [$changed->getStartUnit()->getSlug(), $changed->getEndUnit()->getSlug(), $changed->getCoefficient()]);
        $this->assertTrue($this->flour->isEqual($changed->getIngredient()));
    }

    public function testRecomputesTheCompatibleUnitsOfTheIngredient(): void
    {
        // Given : la tasse est reliée au gramme
        $this->conversionRepository->save(new ConversionBuilder()->withId('cup')->withIngredient($this->flour)->withStartUnit($this->cup)->withEndUnit($this->gramme)->withCoefficient(125)->build());

        // When : elle se relie au verre, isolé du reste
        ($this->handler)($this->command('cup', 'tasse', 'verre', 2));

        // Then : ni la tasse ni le verre ne se convertissent plus depuis la cuillère
        $this->assertSame(['cuillere-a-soupe', 'gramme', 'kilo'], $this->compatibleUnitSlugs());
    }

    public function testRefusesAChangeAfterWhichTheRecipeUnitNoLongerConvertsToThePurchaseUnit(): void
    {
        // Then : la cuillère est reliée au kilo par cette seule conversion
        $this->expectExceptionObject(ConversionException::requiredByIngredient());

        // When : elle se relierait à la tasse, qui ne mène à rien
        ($this->handler)($this->command('spoon-to-gramme', 'cuillere-a-soupe', 'tasse', 10));
    }

    public function testNothingIsWrittenWhenTheChangeIsRefused(): void
    {
        // When
        try {
            ($this->handler)($this->command('spoon-to-gramme', 'cuillere-a-soupe', 'tasse', 10));
            $this->fail('La cuillère ne se convertirait plus en kilo.');
        } catch (ConversionException) {
        }

        // Then
        $unchanged = $this->conversionRepository->findById('spoon-to-gramme');
        $this->assertSame(['cuillere-a-soupe', 'gramme', 10.0], [$unchanged->getStartUnit()->getSlug(), $unchanged->getEndUnit()->getSlug(), $unchanged->getCoefficient()]);
        $this->assertSame(['cuillere-a-soupe', 'kilo'], $this->compatibleUnitSlugs());
    }

    public function testAnyChangeIsAllowedWhenTheRecipeAndPurchaseUnitsAreTheSame(): void
    {
        // Given : un oeuf acheté et cuisiné à l'unité, avec une conversion facultative
        $egg = new IngredientBuilder()->isEgg()->withCreator($this->user, true)->build();
        $this->ingredientRepository->save($egg);
        $this->conversionRepository->save(new ConversionBuilder()->withId('egg')->withIngredient($egg)->withStartUnit($this->gramme)->withEndUnit($this->kilo)->withCoefficient(0.001)->build());

        // When
        ($this->handler)(new UpdateIngredientConversionCommand('egg', $egg->getSlug(), 'tasse', 'verre', 3, $this->user->getId()));

        // Then
        $this->assertSame('tasse', $this->conversionRepository->findById('egg')->getStartUnit()->getSlug());
    }

    public function testRefusesAPairTheIngredientAlreadyHasInAnotherConversion(): void
    {
        // Given
        $this->conversionRepository->save(new ConversionBuilder()->withId('cup')->withIngredient($this->flour)->withStartUnit($this->cup)->withEndUnit($this->gramme)->withCoefficient(125)->build());

        // Then : la tasse > gramme existe déjà, dans l'autre sens aussi
        $this->expectExceptionObject(ConversionException::alreadyExists());

        // When
        ($this->handler)($this->command('spoon-to-gramme', 'gramme', 'tasse', 0.008));
    }

    public function testRefusesTwoIdenticalUnits(): void
    {
        // Then
        $this->expectExceptionObject(ConversionException::sameUnit());

        // When
        ($this->handler)($this->command('spoon-to-gramme', 'gramme', 'gramme', 1));
    }

    public function testRefusesACoefficientThatIsNotAPositiveNumber(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::invalidCoefficient());

        // When
        ($this->handler)($this->command(coefficient: 0));
    }

    public function testAnUniversalConversionIsUnknownToThisRoute(): void
    {
        // Then : elle ne se modifie pas par ce chemin
        $this->expectExceptionObject(ConversionException::notFound('kilo-to-gramme'));

        // When
        ($this->handler)($this->command('kilo-to-gramme', 'kilo', 'gramme', 999));
    }

    public function testAnotherIngredientsConversionIsUnknownToThisRoute(): void
    {
        // Given : une conversion du sel du même utilisateur
        $salt = new IngredientBuilder()->withName('sel')->withCreator($this->user, true)->build();
        $this->ingredientRepository->save($salt);
        $this->conversionRepository->save(new ConversionBuilder()->withId('salt')->withIngredient($salt)->withStartUnit($this->cup)->withEndUnit($this->gramme)->withCoefficient(200)->build());

        // Then
        $this->expectExceptionObject(ConversionException::notFound('salt'));

        // When : par le chemin de la farine
        ($this->handler)($this->command('salt', 'tasse', 'gramme', 1));
    }

    public function testAnUnknownConversionIsUnknown(): void
    {
        // Then
        $this->expectExceptionObject(ConversionException::notFound('nope'));

        // When
        ($this->handler)($this->command('nope'));
    }

    public function testTheIngredientOfAnotherUserIsUnknownEvenToAnAdministrator(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::notFound($this->flour->getSlug()));

        // When
        ($this->handler)($this->command(userId: new UserBuilder()->isAdmin()->build()->getId()));
    }
}
