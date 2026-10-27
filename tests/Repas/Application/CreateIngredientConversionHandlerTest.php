<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\CreateIngredientConversion\CreateIngredientConversionCommand;
use Repas\Repas\Application\Command\CreateIngredientConversion\CreateIngredientConversionHandler;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;

class CreateIngredientConversionHandlerTest extends IngredientConversionHandlerTestCase
{
    private CreateIngredientConversionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new CreateIngredientConversionHandler($this->ingredientRepository, $this->conversionRepository, $this->slugResolver, $this->ownConversions);
    }

    private function command(string $start = 'tasse', string $end = 'gramme', float $coefficient = 125, string $id = 'new-conversion', ?string $slug = null, ?string $userId = null): CreateIngredientConversionCommand
    {
        return new CreateIngredientConversionCommand($id, $slug ?? $this->flour->getSlug(), $start, $end, $coefficient, $userId ?? $this->user->getId());
    }

    public function testAddsAConversionOwnedByTheIngredientBetweenAnyTwoUnits(): void
    {
        // When
        ($this->handler)($this->command());

        // Then : une conversion PROPRE à la farine (jamais universelle), avec ce que l'utilisateur a dit
        $created = $this->conversionRepository->findById('new-conversion');
        $this->assertTrue($this->flour->isEqual($created->getIngredient()));
        $this->assertSame(['tasse', 'gramme', 125.0], [$created->getStartUnit()->getSlug(), $created->getEndUnit()->getSlug(), $created->getCoefficient()]);
        $this->assertSame(['new-conversion', 'spoon-to-gramme'], $this->ownIds());
    }

    public function testAddsAsManyConversionsAsTheUserWants(): void
    {
        // When
        ($this->handler)($this->command('tasse', 'gramme', 125, 'cup'));
        ($this->handler)($this->command('verre', 'tasse', 2, 'glass'));
        ($this->handler)($this->command('kilo', 'verre', 5, 'kilo-to-glass'));

        // Then
        $this->assertSame(['cup', 'glass', 'kilo-to-glass', 'spoon-to-gramme'], $this->ownIds());
    }

    public function testRecomputesTheCompatibleUnitsOfTheIngredient(): void
    {
        // When : la tasse se relie au gramme, que la cuillère rejoint déjà
        ($this->handler)($this->command('tasse', 'gramme'));

        // Then : la tasse devient un choix possible dans une recette
        $this->assertSame(['cuillere-a-soupe', 'gramme', 'kilo', 'tasse'], $this->compatibleUnitSlugs());
    }

    public function testAnUniversalConversionOnTheSamePairDoesNotPreventAnOwnOne(): void
    {
        // When : la farine pèse autrement que la règle commune
        ($this->handler)($this->command('kilo', 'gramme', 990, 'own-kilo'));

        // Then : la sienne passe en premier dans le graphe, la universelle reste intacte
        $this->assertSame(['own-kilo', 'spoon-to-gramme'], $this->ownIds());
        $this->assertSame(1000.0, $this->conversionRepository->findById('kilo-to-gramme')->getCoefficient());
        $this->assertNull($this->conversionRepository->findById('kilo-to-gramme')->getIngredient());
    }

    public function testRefusesAPairTheIngredientAlreadyHasInTheSameDirection(): void
    {
        // Then
        $this->expectExceptionObject(ConversionException::alreadyExists());

        // When
        ($this->handler)($this->command('cuillere-a-soupe', 'gramme', 12));
    }

    public function testRefusesAPairTheIngredientAlreadyHasInTheOppositeDirection(): void
    {
        // Then
        $this->expectExceptionObject(ConversionException::alreadyExists());

        // When
        ($this->handler)($this->command('gramme', 'cuillere-a-soupe', 0.1));
    }

    public function testRefusesTwoIdenticalUnits(): void
    {
        // Then
        $this->expectExceptionObject(ConversionException::sameUnit());

        // When
        ($this->handler)($this->command('tasse', 'tasse', 1));
    }

    #[DataProvider('invalidCoefficients')]
    public function testRefusesACoefficientThatIsNotAFinitePositiveNumber(float $coefficient): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::invalidCoefficient());

        // When
        ($this->handler)($this->command('tasse', 'gramme', $coefficient));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidCoefficients(): iterable
    {
        yield 'zéro' => [0.0];
        yield 'négatif' => [-3.0];
        yield 'infini' => [INF];
        yield 'pas un nombre' => [NAN];
    }

    public function testRefusesAnUnknownUnit(): void
    {
        // Then
        $this->expectExceptionObject(UnitException::notFound('inconnue'));

        // When
        ($this->handler)($this->command('inconnue', 'gramme'));
    }

    public function testNothingIsWrittenWhenTheCommandIsRefused(): void
    {
        // When
        try {
            ($this->handler)($this->command('cuillere-a-soupe', 'gramme', 12, 'duplicate'));
            $this->fail('La paire existe déjà.');
        } catch (ConversionException) {
        }

        // Then
        $this->assertSame(['spoon-to-gramme'], $this->ownIds());
        $this->assertSame(['cuillere-a-soupe', 'kilo'], $this->compatibleUnitSlugs());
    }

    public function testTheIngredientOfAnotherUserIsUnknownEvenToAnAdministrator(): void
    {
        // Then : le propriétaire seul, sans passe-droit
        $this->expectExceptionObject(IngredientException::notFound($this->flour->getSlug()));

        // When
        ($this->handler)($this->command(userId: 'another-user-or-admin'));
    }

    public function testAnOldSlugDesignatesTheCurrentIngredientOfTheUser(): void
    {
        // Given : l'ingrédient de l'utilisateur est la copie d'un ancien ingrédient, dont un onglet périmé envoie encore le slug
        $copy = new IngredientBuilder()->withName('farine copie')->withCreator($this->user, true)->build();
        $this->ingredientRepository->save($copy);
        $this->aliasRepository->save(IngredientAlias::create($this->user->getId(), 'ancien-slug', $copy->getSlug(), AliasKind::ADOPTION, 'activation', new DateTimeImmutable()));

        // When
        ($this->handler)($this->command(slug: 'ancien-slug'));

        // Then : la conversion est celle de la copie
        $this->assertTrue($copy->isEqual($this->conversionRepository->findById('new-conversion')->getIngredient()));
    }
}
