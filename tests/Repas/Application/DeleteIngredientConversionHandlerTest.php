<?php

namespace Repas\Tests\Repas\Application;


use Repas\Repas\Application\Command\DeleteIngredientConversion\DeleteIngredientConversionCommand;
use Repas\Repas\Application\Command\DeleteIngredientConversion\DeleteIngredientConversionHandler;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;

class DeleteIngredientConversionHandlerTest extends IngredientConversionHandlerTestCase
{
    private DeleteIngredientConversionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new DeleteIngredientConversionHandler($this->ingredientRepository, $this->conversionRepository, $this->slugResolver, $this->ownConversions);
    }

    private function command(string $id, ?string $userId = null): DeleteIngredientConversionCommand
    {
        return new DeleteIngredientConversionCommand($id, $this->flour->getSlug(), $userId ?? $this->user->getId());
    }

    private function addCup(): void
    {
        $this->conversionRepository->save(new ConversionBuilder()->withId('cup')->withIngredient($this->flour)->withStartUnit($this->cup)->withEndUnit($this->gramme)->withCoefficient(125)->build());
    }

    public function testRemovesAConversionTheRecipeUnitDoesNotNeed(): void
    {
        // Given
        $this->addCup();

        // When
        ($this->handler)($this->command('cup'));

        // Then : seule la tasse a disparu
        $this->assertSame(['spoon-to-gramme'], $this->ownIds());
    }

    public function testRecomputesTheCompatibleUnitsOfTheIngredient(): void
    {
        // Given : la tasse est un choix possible, tant que sa conversion existe
        $this->addCup();
        $this->flour->setCompatibleUnits(Tab::fromArray($this->spoon, $this->gramme, $this->kilo, $this->cup));
        $this->ingredientRepository->save($this->flour);

        // When
        ($this->handler)($this->command('cup'));

        // Then
        $this->assertSame(['cuillere-a-soupe', 'gramme', 'kilo'], $this->compatibleUnitSlugs());
    }

    public function testRefusesToRemoveTheConversionThatLinksTheRecipeUnitToThePurchaseUnit(): void
    {
        // Then : sans elle, la cuillère ne se convertit plus en kilo
        $this->expectExceptionObject(ConversionException::requiredByIngredient());

        // When
        ($this->handler)($this->command('spoon-to-gramme'));
    }

    public function testNothingIsRemovedWhenTheRemovalIsRefused(): void
    {
        // When
        try {
            ($this->handler)($this->command('spoon-to-gramme'));
            $this->fail('La cuillère ne se convertirait plus en kilo.');
        } catch (ConversionException) {
        }

        // Then
        $this->assertSame(['spoon-to-gramme'], $this->ownIds());
        $this->assertSame(['cuillere-a-soupe', 'kilo'], $this->compatibleUnitSlugs());
    }

    public function testRemovesTheNeededConversionWhenAnotherWayStillLinksTheUnits(): void
    {
        // Given : la cuillère a aussi un chemin direct vers le kilo
        $this->conversionRepository->save(new ConversionBuilder()->withId('spoon-to-kilo')->withIngredient($this->flour)->withStartUnit($this->spoon)->withEndUnit($this->kilo)->withCoefficient(0.01)->build());

        // When
        ($this->handler)($this->command('spoon-to-gramme'));

        // Then
        $this->assertSame(['spoon-to-kilo'], $this->ownIds());
    }

    public function testAnythingCanBeRemovedWhenTheRecipeAndPurchaseUnitsAreTheSame(): void
    {
        // Given
        $egg = new IngredientBuilder()->isEgg()->withCreator($this->user, true)->build();
        $this->ingredientRepository->save($egg);
        $this->conversionRepository->save(new ConversionBuilder()->withId('egg')->withIngredient($egg)->withStartUnit($this->gramme)->withEndUnit($this->kilo)->withCoefficient(0.001)->build());

        // When
        ($this->handler)(new DeleteIngredientConversionCommand('egg', $egg->getSlug(), $this->user->getId()));

        // Then
        $this->assertCount(0, $this->conversionRepository->findByIngredient($egg));
    }

    public function testAnUniversalConversionIsNeverRemovedByThisRoute(): void
    {
        // Then
        $this->expectExceptionObject(ConversionException::notFound('kilo-to-gramme'));

        // When
        try {
            ($this->handler)($this->command('kilo-to-gramme'));
        } finally {
            // La universelle est intacte
            $this->assertSame(1000.0, $this->conversionRepository->findById('kilo-to-gramme')->getCoefficient());
        }
    }

    public function testAnotherIngredientsConversionIsUnknownToThisRoute(): void
    {
        // Given
        $salt = new IngredientBuilder()->withName('sel')->withCreator($this->user, true)->build();
        $this->ingredientRepository->save($salt);
        $this->conversionRepository->save(new ConversionBuilder()->withId('salt')->withIngredient($salt)->withStartUnit($this->cup)->withEndUnit($this->gramme)->withCoefficient(200)->build());

        // Then
        $this->expectExceptionObject(ConversionException::notFound('salt'));

        // When : par le chemin de la farine
        ($this->handler)($this->command('salt'));
    }

    public function testTheIngredientOfAnotherUserIsUnknownEvenToAnAdministrator(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::notFound($this->flour->getSlug()));

        // When
        ($this->handler)($this->command('spoon-to-gramme', new UserBuilder()->isAdmin()->build()->getId()));
    }
}
