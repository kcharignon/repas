<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeComponent;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;

/**
 * Une recette qui en utilise une autre (ADR 0040) dans le modèle : le composant et ses bornes, la recette qui porte ses composants, le drapeau
 * « composants modifiés » que le dépôt lit pour ne les écrire que quand ils ont été posés, et les refus de base.
 */
class RecipeComponentTest extends TestCase
{
    private const string RECIPE = 'tarte-id';

    /**
     * @return iterable<string, array{float}>
     */
    public static function validMultipliers(): iterable
    {
        yield 'le minimum, un centième' => [0.01];
        yield 'une demi-fois' => [0.5];
        yield 'une fois' => [1.0];
        yield 'deux fois' => [2.0];
        yield 'le maximum, cent fois' => [100.0];
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidMultipliers(): iterable
    {
        yield 'zéro' => [0.0];
        yield 'négatif' => [-1.0];
        yield 'sous le minimum' => [0.009];
        yield 'au-dessus du maximum' => [100.01];
        yield 'plus grand encore' => [1_000_000.0];
        yield 'pas un nombre' => [NAN];
        yield 'infini' => [INF];
        yield 'moins l\'infini' => [-INF];
    }

    #[DataProvider('validMultipliers')]
    public function testAMultiplierFromAHundredthToAHundredTimesIsAccepted(float $multiplier): void
    {
        // When
        $component = RecipeComponent::create('edge-id', self::RECIPE, 'pate-id', $multiplier, 3);

        // Then
        $this->assertSame([$multiplier, 3, self::RECIPE, 'pate-id'], [$component->getMultiplier(), $component->getPosition(), $component->getRecipeId(), $component->getComponentId()]);
        $this->assertTrue(RecipeComponent::isMultiplier($multiplier));
    }

    #[DataProvider('invalidMultipliers')]
    public function testAMultiplierOutOfBoundsIsRefusedByTheComponentItself(float $multiplier): void
    {
        // Then
        $this->assertFalse(RecipeComponent::isMultiplier($multiplier));
        $this->expectExceptionObject(RecipeException::componentMultiplierInvalid());

        // When
        RecipeComponent::create('edge-id', self::RECIPE, 'pate-id', $multiplier);
    }

    public function testLoadingJudgesNothingSoAnOldValueIsStillReadAndCopied(): void
    {
        // When : une valeur déjà en base, plus ancienne que les bornes
        $component = RecipeComponent::load(['id' => 'edge-id', 'recipe_id' => self::RECIPE, 'component_id' => 'pate-id', 'multiplier' => 250.0]);

        // Then
        $this->assertSame([250.0, 0], [$component->getMultiplier(), $component->getPosition()]);
    }

    public function testMovingAComponentKeepsTheOtherOneIntact(): void
    {
        // Given
        $component = RecipeComponent::create('edge-id', self::RECIPE, 'pate-id', 2.0, 0);

        // When
        $moved = $component->withPosition(4);

        // Then : le modèle ne se modifie pas
        $this->assertSame([0, 4], [$component->getPosition(), $moved->getPosition()]);
        $this->assertSame([$component->getId(), $component->getMultiplier(), $component->getComponentId()], [$moved->getId(), $moved->getMultiplier(), $moved->getComponentId()]);
    }

    public function testARecipeHasNoComponentByDefaultWhateverTheWayItIsBuilt(): void
    {
        // Given & Then : la création, le chargement d'une ligne sans composants et le builder donnent une recette qui n'en a pas, et rien à écrire
        $created = Recipe::create(self::RECIPE, 'Tarte', 4, 'author-id', new RecipeTypeBuilder()->isMeal()->build(), Tab::newEmptyTyped(RecipeRow::class), null);
        $loaded = Recipe::load(['id' => self::RECIPE, 'name' => 'Tarte', 'serving' => 4, 'author_id' => 'author-id', 'type' => new RecipeTypeBuilder()->isMeal()->build(), 'rows' => Tab::newEmptyTyped(RecipeRow::class), 'original_id' => null]);

        foreach ([$created, $loaded, new RecipeBuilder()->build()] as $recipe) {
            $this->assertFalse($recipe->hasComponents());
            $this->assertSame([], $recipe->componentIds());
            $this->assertSame(0, $recipe->getComponents()->count());
            $this->assertSame(0, $recipe->getComponentCount());
            $this->assertFalse($recipe->areComponentsChanged());
        }
    }

    public function testALoadedRecipeKeepsItsComponentsInTheirOrderAndHasNothingToWrite(): void
    {
        // Given : une recette lue en base avec deux composants
        $recipe = new RecipeBuilder()->withId(self::RECIPE)
            ->withComponent(new RecipeBuilder()->withId('pate-id'), 2.0)
            ->withComponent(new RecipeBuilder()->withId('creme-id'), 0.5)
            ->build();

        // Then : le drapeau n'est pas posé par le chargement
        $this->assertTrue($recipe->hasComponents());
        $this->assertSame(['pate-id', 'creme-id'], $recipe->componentIds());
        $this->assertSame(2, $recipe->getComponentCount());
        $this->assertFalse($recipe->areComponentsChanged());
    }

    public function testAModelLoadedWithoutItsEdgesStillKnowsThatItHasComponents(): void
    {
        // When : `findByIds` ne lit pas les arêtes mais lit `component_count`
        $recipe = Recipe::load(['id' => self::RECIPE, 'name' => 'Pâte', 'serving' => 4, 'author_id' => 'author-id', 'type' => new RecipeTypeBuilder()->isMeal()->build(), 'rows' => Tab::newEmptyTyped(RecipeRow::class), 'original_id' => null, 'components' => null, 'component_count' => 2]);

        // Then
        $this->assertTrue($recipe->hasComponents());
        $this->assertSame([2, 0, []], [$recipe->getComponentCount(), $recipe->getComponents()->count(), $recipe->componentIds()]);
        $this->assertFalse($recipe->areComponentsChanged());
    }

    public function testSettingTheComponentsRaisesTheFlagAndKeepsTheOrderAsThePosition(): void
    {
        // Given
        $recipe = new RecipeBuilder()->withId(self::RECIPE)->build();

        // When : les positions données sont ignorées, l'ordre reçu est la position
        $recipe->setComponents($this->components(['pate-id' => 2.0, 'creme-id' => 0.5], [7, 3]));

        // Then
        $this->assertTrue($recipe->areComponentsChanged());
        $this->assertTrue($recipe->hasComponents());
        $this->assertSame(['pate-id', 'creme-id'], $recipe->componentIds());
        $this->assertSame([0, 1], array_values($recipe->getComponents()->map(static fn(RecipeComponent $component): int => $component->getPosition())->toArray()));
        $this->assertSame(2, $recipe->getComponentCount());
    }

    public function testOnlySettingTheComponentsRaisesTheFlag(): void
    {
        // Given : une recette qui a des composants
        $recipe = new RecipeBuilder()->withId(self::RECIPE)->withComponent(new RecipeBuilder()->withId('pate-id'), 2.0)->build();

        // When : tout ce qui n'est pas les composants
        $recipe->setName('Autre nom');
        $recipe->setServing(6);
        $recipe->setType(new RecipeTypeBuilder()->isDessert()->build());
        $recipe->setRows(Tab::newEmptyTyped(RecipeRow::class));
        $recipe->changeSecret(true, $recipe->getAuthorId());
        $recipe->changeFavorite(true, $recipe->getAuthorId());
        $recipe->update('Encore un nom', new RecipeTypeBuilder()->isMeal()->build(), 4);

        // Then : les composants sont intacts et rien ne dit au dépôt de les écrire
        $this->assertFalse($recipe->areComponentsChanged());
        $this->assertSame(['pate-id'], $recipe->componentIds());
    }

    public function testRemovingEveryComponentIsAChangeButNothingToRemoveIsNot(): void
    {
        // Given
        $withComponent = new RecipeBuilder()->withId(self::RECIPE)->withComponent(new RecipeBuilder()->withId('pate-id'))->build();
        $without = new RecipeBuilder()->withId('other-id')->build();

        // When
        $withComponent->setComponents(Tab::newEmptyTyped(RecipeComponent::class));
        $without->setComponents(Tab::newEmptyTyped(RecipeComponent::class));

        // Then : retirer ce qui existe se récrit ; `[]` sur une recette sans composant n'écrit rien (zéro composant, rien ne change)
        $this->assertTrue($withComponent->areComponentsChanged());
        $this->assertFalse($withComponent->hasComponents());
        $this->assertFalse($without->areComponentsChanged());
    }

    public function testARecipeIsCreatedWithItsComponents(): void
    {
        // When
        $recipe = Recipe::create(self::RECIPE, 'Tarte', 4, 'author-id', new RecipeTypeBuilder()->isMeal()->build(), Tab::newEmptyTyped(RecipeRow::class), null, components: $this->components(['pate-id' => 1.0]));

        // Then : les arêtes sont à écrire à la première écriture
        $this->assertSame(['pate-id'], $recipe->componentIds());
        $this->assertTrue($recipe->areComponentsChanged());
    }

    public function testAnEmptyOrAbsentListOfComponentsAtCreationWritesNothing(): void
    {
        // When
        $absent = Recipe::create(self::RECIPE, 'Tarte', 4, 'author-id', new RecipeTypeBuilder()->isMeal()->build(), Tab::newEmptyTyped(RecipeRow::class), null);
        $empty = Recipe::create(self::RECIPE, 'Tarte', 4, 'author-id', new RecipeTypeBuilder()->isMeal()->build(), Tab::newEmptyTyped(RecipeRow::class), null, components: Tab::newEmptyTyped(RecipeComponent::class));

        // Then
        $this->assertFalse($absent->areComponentsChanged());
        $this->assertFalse($empty->areComponentsChanged());
        $this->assertFalse($empty->hasComponents());
    }

    public function testTenComponentsAreEnoughButTheEleventhIsRefusedAndNothingIsSet(): void
    {
        // Given
        $recipe = new RecipeBuilder()->withId(self::RECIPE)->build();
        $ten = $this->components($this->multipliers(10));
        $eleven = $this->components($this->multipliers(11));

        // When
        $recipe->setComponents($ten);

        // Then
        $this->assertSame(10, $recipe->getComponents()->count());
        try {
            $recipe->setComponents($eleven);
            $this->fail('Onze composants doivent être refusés');
        } catch (RecipeException $exception) {
            $this->assertSame([RecipeException::COMPONENT_LIMIT_REACHED, 422], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertSame(10, $recipe->getComponents()->count(), 'Un refus ne change rien');
    }

    public function testTheSameRecipeTwiceIsRefused(): void
    {
        // Given
        $recipe = new RecipeBuilder()->withId(self::RECIPE)->build();
        $twice = Tab::fromArray(
            RecipeComponent::create('edge-1', self::RECIPE, 'pate-id', 1.0),
            RecipeComponent::create('edge-2', self::RECIPE, 'pate-id', 2.0),
        );

        // Then
        $this->expectExceptionObject(RecipeException::componentDuplicate());

        // When
        $recipe->setComponents($twice);
    }

    public function testARecipeDoesNotUseItself(): void
    {
        // Given
        $recipe = new RecipeBuilder()->withId(self::RECIPE)->build();

        // Then
        $this->expectExceptionObject(RecipeException::componentSelf());

        // When
        $recipe->setComponents($this->components([self::RECIPE => 1.0]));
    }

    public function testAnOutOfBoundsMultiplierIsRefusedAgainByTheRecipeBecauseALoadedComponentIsNotJudged(): void
    {
        // Given : un composant reconstruit sans contrôle (`load`), comme le ferait un builder ou une donnée ancienne
        $recipe = new RecipeBuilder()->withId(self::RECIPE)->build();
        $tooMany = Tab::fromArray(RecipeComponent::load(['id' => 'edge-id', 'recipe_id' => self::RECIPE, 'component_id' => 'pate-id', 'multiplier' => 500.0]));

        // Then
        $this->expectExceptionObject(RecipeException::componentMultiplierInvalid());

        // When : la recette est la garde finale
        $recipe->setComponents($tooMany);
    }

    public function testTheChecksFollowTheOrderOfTheDecision(): void
    {
        // Given : le nombre, puis les doublons, puis les multiplicateurs, puis la recette elle-même
        $recipe = new RecipeBuilder()->withId(self::RECIPE)->build();
        $load = static fn(string $id, string $component, float $multiplier): RecipeComponent => RecipeComponent::load(['id' => $id, 'recipe_id' => self::RECIPE, 'component_id' => $component, 'multiplier' => $multiplier]);
        $eleven = Tab::fromArray(array_map(static fn(int $i): RecipeComponent => $load("edge-$i", $i === 0 ? self::RECIPE : "c-$i", 500.0), range(0, 10)));
        $duplicateAndBadMultiplier = Tab::fromArray($load('edge-1', 'pate-id', 500.0), $load('edge-2', 'pate-id', 1.0));
        $badMultiplierAndSelf = Tab::fromArray($load('edge-1', 'pate-id', 500.0), $load('edge-2', self::RECIPE, 1.0));

        // When & Then
        foreach ([[$eleven, RecipeException::COMPONENT_LIMIT_REACHED], [$duplicateAndBadMultiplier, RecipeException::COMPONENT_DUPLICATE], [$badMultiplierAndSelf, RecipeException::COMPONENT_MULTIPLIER_INVALID]] as [$components, $message]) {
            try {
                $recipe->setComponents($components);
                $this->fail("Le refus $message était attendu");
            } catch (RecipeException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testAComponentOfAnotherRecipeIsAProgrammingErrorNotARefusal(): void
    {
        // Given
        $recipe = new RecipeBuilder()->withId(self::RECIPE)->build();

        // Then
        $this->expectException(\LogicException::class);

        // When
        $recipe->setComponents(Tab::fromArray(RecipeComponent::create('edge-id', 'another-recipe-id', 'pate-id', 1.0)));
    }

    public function testARecipeWithoutOwnerNeverGetsComponents(): void
    {
        // Given : la neutre d'une recette Marmiton, qu'un administrateur est en train de modifier
        $neutral = new RecipeBuilder()->withId(self::RECIPE)->asMarmitonNeutral()->build()->allowAdministratorEdition();

        // When & Then : des composants non vides sont refusés, sans rien poser
        try {
            $neutral->setComponents($this->components(['pate-id' => 1.0]));
            $this->fail('Une recette sans propriétaire ne reçoit pas de composants');
        } catch (RecipeException $exception) {
            $this->assertSame([RecipeException::COMPONENT_OWNERLESS, 422], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertFalse($neutral->areComponentsChanged());
        $this->assertFalse($neutral->hasComponents());

        // Et `[]` est sans effet
        $neutral->setComponents(Tab::newEmptyTyped(RecipeComponent::class));
        $this->assertFalse($neutral->areComponentsChanged());
    }

    public function testAFrozenRecipeWithoutOwnerRefusesBeforeAnythingElse(): void
    {
        // Given : la neutre, que personne n'a le droit de modifier
        $neutral = new RecipeBuilder()->withId(self::RECIPE)->asMarmitonNeutral()->build();

        // Then
        $this->expectExceptionObject(RecipeException::frozen());

        // When
        $neutral->setComponents($this->components(['pate-id' => 1.0]));
    }

    public function testTheCopyOfARecipeDoesNotCarryTheComponentsYet(): void
    {
        // Given : COMP-003 copiera les recettes utilisées ; en attendant la copie n'en a pas (et n'écrit rien)
        $original = new RecipeBuilder()->withId(self::RECIPE)->withAuthorId('author-id')->withComponent(new RecipeBuilder()->withId('pate-id'))->build();

        // When
        $copy = Recipe::copyFromOriginal('copy-id', $original, 'copier-id', [], static fn(): string => 'row-id');

        // Then
        $this->assertFalse($copy->hasComponents());
        $this->assertFalse($copy->areComponentsChanged());
    }

    /**
     * @param array<string, float> $multipliers multiplicateur par identifiant de recette utilisée, dans l'ordre
     * @param list<int>|null $positions
     * @return Tab<RecipeComponent>
     */
    private function components(array $multipliers, ?array $positions = null): Tab
    {
        $components = [];
        $index = 0;
        foreach ($multipliers as $componentId => $multiplier) {
            $components[] = RecipeComponent::create('edge-' . $index, self::RECIPE, (string) $componentId, $multiplier, $positions[$index] ?? $index);
            ++$index;
        }

        return Tab::fromArray($components);
    }

    /**
     * @return array<string, float>
     */
    private function multipliers(int $count): array
    {
        $multipliers = [];
        for ($i = 1; $i <= $count; ++$i) {
            $multipliers["component-$i"] = 1.0;
        }

        return $multipliers;
    }
}
