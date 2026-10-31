<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Domain\Interface\ListRoutingRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Où va chaque produit (ADR 0036 décision 3) sur la VRAIE base : le choix du produit, sinon le type de son rayon, sinon la liste principale,
 * pour N produits en UNE lecture. La règle elle-même est prouvée, pure, par `ListDestinationResolverTest`.
 */
class ListRoutingRepositoryTest extends DatabaseTestCase
{
    private ListRoutingRepository $routing;
    private DataPersister $persister;
    private User $owner;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->routing = static::getContainer()->get(ListRoutingRepository::class);
        $this->persister = new DataPersister(static::getContainer());
        $this->owner = new UserBuilder()->build();
        $this->other = new UserBuilder()->withId('other-id')->withEmail('other@example.com')->build();
        $this->persister->saveUser($this->owner);
        $this->persister->saveUser($this->other);
    }

    public function testAProductWithNoChoiceInAnUntypedDepartmentGoesToTheMainList(): void
    {
        // Given
        $cheese = $this->savedIngredient('Fromage râpé', new DepartmentBuilder()->isCheese());

        // When / Then
        $this->assertSame([$cheese->getSlug() => null], $this->routing->destinationsFor($this->owner->getId(), [$cheese->getSlug()]));
    }

    public function testAProductGoesToTheTypeOfItsDepartment(): void
    {
        // Given : « Boucherie » tient le rayon de la viande
        $this->savedType('butcher', 'Boucherie', ['viande']);
        $meat = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat());

        // When / Then
        $this->assertSame([$meat->getSlug() => 'butcher'], $this->routing->destinationsFor($this->owner->getId(), [$meat->getSlug()]));
    }

    public function testTheChoiceMadeOnTheProductWinsOverItsDepartment(): void
    {
        // Given : le steak est dans le rayon de « Boucherie » mais choisi pour « Marché »
        $this->savedType('butcher', 'Boucherie', ['viande']);
        $this->savedType('market', 'Marché', []);
        $meat = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat(), 'market');

        // When / Then
        $this->assertSame([$meat->getSlug() => 'market'], $this->routing->destinationsFor($this->owner->getId(), [$meat->getSlug()]));
    }

    public function testAProductChosenForTheMainListStaysThereInATypedDepartment(): void
    {
        // Given
        $this->savedType('butcher', 'Boucherie', ['viande']);
        $meat = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat(), 'main');

        // When / Then
        $this->assertSame([$meat->getSlug() => null], $this->routing->destinationsFor($this->owner->getId(), [$meat->getSlug()]));
    }

    public function testAChoiceOnAProductOfAnUntypedDepartmentGoesToItsType(): void
    {
        // Given
        $this->savedType('market', 'Marché', []);
        $cheese = $this->savedIngredient('Fromage râpé', new DepartmentBuilder()->isCheese(), 'market');

        // When / Then
        $this->assertSame([$cheese->getSlug() => 'market'], $this->routing->destinationsFor($this->owner->getId(), [$cheese->getSlug()]));
    }

    public function testADeletedTypeFallsBackOnTheNextRule(): void
    {
        // Given : un choix qui désigne un type supprimé (le rayon est dans un autre type), et un autre dont le rayon n'a pas de type
        $this->savedType('butcher', 'Boucherie', ['viande']);
        $meat = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat(), 'gone-type');
        $cheese = $this->savedIngredient('Fromage râpé', new DepartmentBuilder()->isCheese(), 'gone-type');

        // When
        $destinations = $this->routing->destinationsFor($this->owner->getId(), [$meat->getSlug(), $cheese->getSlug()]);

        // Then
        $this->assertSame([$meat->getSlug() => 'butcher', $cheese->getSlug() => null], $destinations);
    }

    public function testAnAttachmentToADeletedTypeIsIgnored(): void
    {
        // Given : un rattachement de rayon resté en base alors que son type n'existe plus
        $meat = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat());
        $this->entityManager->getConnection()->executeStatement("INSERT INTO department_list_type (owner_id, department_slug, list_type_id) VALUES (?, 'viande', 'gone-type')", [$this->owner->getId()]);

        // When / Then
        $this->assertSame([$meat->getSlug() => null], $this->routing->destinationsFor($this->owner->getId(), [$meat->getSlug()]));
    }

    public function testTheTypesOfAnotherAccountNeverCount(): void
    {
        // Given : les types d'un AUTRE compte tiennent le rayon et sont choisis sur le produit du propriétaire (donnée forgée)
        $this->savedType('their-butcher', 'Boucherie', ['viande'], $this->other);
        $meat = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat(), 'their-butcher');

        // When / Then : la liste principale
        $this->assertSame([$meat->getSlug() => null], $this->routing->destinationsFor($this->owner->getId(), [$meat->getSlug()]));
    }

    public function testTheAttachmentsOfAnotherAccountNeverCount(): void
    {
        // Given : « Boucherie » de l'autre compte tient « viande » ; le propriétaire n'a rien rattaché
        $this->savedType('their-butcher', 'Boucherie', ['viande'], $this->other);
        $meat = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat());

        // When / Then
        $this->assertSame([$meat->getSlug() => null], $this->routing->destinationsFor($this->owner->getId(), [$meat->getSlug()]));
    }

    public function testAnUnknownProductOrOneOfAnotherAccountGoesToTheMainList(): void
    {
        // Given : le produit d'un autre compte, rangé dans un rayon que le propriétaire a typé
        $this->savedType('butcher', 'Boucherie', ['viande']);
        $theirs = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat(), null, $this->other);

        // When
        $destinations = $this->routing->destinationsFor($this->owner->getId(), [$theirs->getSlug(), 'inconnu']);

        // Then : chaque slug demandé est dans la réponse
        $this->assertSame([$theirs->getSlug() => null, 'inconnu' => null], $destinations);
    }

    public function testThePlacementOfAProductIsItsDestinationAndItsPurchaseUnitInOneQuery(): void
    {
        // Given : le steak va dans la Boucherie, le sucre est choisi pour le Marché
        $this->savedType('butcher', 'Boucherie', ['viande']);
        $this->savedType('market', 'Marché', []);
        $meat = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat());
        $apart = $this->savedIngredient('Sucre', new DepartmentBuilder()->isMiscellaneous(), 'market');
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $placements = $this->routing->placementsFor($this->owner->getId(), [$meat->getSlug(), $apart->getSlug()]);

        // Then
        $this->assertSame(1, $counter->count());
        $this->assertSame(
            [$meat->getSlug() => ['butcher', $meat->getDefaultPurchaseUnit()->getSlug()], $apart->getSlug() => ['market', $apart->getDefaultPurchaseUnit()->getSlug()]],
            array_map(static fn($placement): array => [$placement->listTypeId, $placement->purchaseUnitSlug], $placements),
        );
    }

    public function testAnUnknownProductOrOneOfAnotherAccountHasNoPlacement(): void
    {
        // Given
        $theirs = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat(), null, $this->other);
        $mine = $this->savedIngredient('Sel', new DepartmentBuilder()->isMiscellaneous());

        // When
        $placements = $this->routing->placementsFor($this->owner->getId(), [$theirs->getSlug(), 'inconnu', $mine->getSlug()]);

        // Then : seuls les produits du propriétaire, la liste principale pour le sien
        $this->assertSame([$mine->getSlug()], array_keys($placements));
        $this->assertNull($placements[$mine->getSlug()]->listTypeId);
        $this->assertSame([], $this->routing->placementsFor($this->owner->getId(), []));
    }

    public function testNoProductMeansNoQuery(): void
    {
        // Given
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $destinations = $this->routing->destinationsFor($this->owner->getId(), []);

        // Then
        $this->assertSame([[], 0], [$destinations, $counter->count()]);
    }

    public function testManyProductsAreReadInOneQuery(): void
    {
        // Given : trois types, trois rayons, un produit à part, des doublons dans la demande
        $this->savedType('butcher', 'Boucherie', ['viande']);
        $this->savedType('dairy', 'Crèmerie', ['fromage']);
        $this->savedType('market', 'Marché', []);
        $meat = $this->savedIngredient('Steak', new DepartmentBuilder()->isMeat());
        $cheese = $this->savedIngredient('Fromage râpé', new DepartmentBuilder()->isCheese());
        $misc = $this->savedIngredient('Sel', new DepartmentBuilder()->isMiscellaneous());
        $apart = $this->savedIngredient('Sucre', new DepartmentBuilder()->isMiscellaneous(), 'market');
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $destinations = $this->routing->destinationsFor($this->owner->getId(), [$meat->getSlug(), $cheese->getSlug(), $misc->getSlug(), $apart->getSlug(), $meat->getSlug()]);

        // Then
        $this->assertSame([$meat->getSlug() => 'butcher', $cheese->getSlug() => 'dairy', $misc->getSlug() => null, $apart->getSlug() => 'market'], $destinations);
        $this->assertSame(1, $counter->count());
    }

    /**
     * @param list<string> $departmentSlugs
     */
    private function savedType(string $id, string $name, array $departmentSlugs, ?User $owner = null): void
    {
        foreach ($departmentSlugs as $slug) {
            $this->persister->saveDepartment(match ($slug) {
                'viande' => new DepartmentBuilder()->isMeat()->build(),
                'fromage' => new DepartmentBuilder()->isCheese()->build(),
                default => new DepartmentBuilder()->isMiscellaneous()->build(),
            });
        }
        $type = ShoppingListType::create($id, ($owner ?? $this->owner)->getId(), $name, new DateTimeImmutable('2026-10-31 10:00:00'))->replaceDepartments($departmentSlugs);
        $this->persister->saveShoppingListType($type);
    }

    private function savedIngredient(string $name, DepartmentBuilder $department, ?string $listDestination = null, ?User $creator = null): Ingredient
    {
        $ingredient = new IngredientBuilder()->withName($name)->withDepartment($department)->withCreator($creator ?? $this->owner, true)->withListDestination($listDestination)->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }
}
