<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Application\Loader\ShoppingListTypeLoader;
use Repas\Repas\Application\Query\GetShoppingListType\ShoppingListTypeDepartmentDTO;
use Repas\Repas\Application\Query\GetShoppingListType\ShoppingListTypeIngredientDTO;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Interface\ShoppingListTypeRepository;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Les types de liste de courses d'un utilisateur sur la vraie base : seuls les siens (jamais ceux d'un autre), leurs rayons, le nombre de
 * produits à part, et un nombre constant de requêtes.
 */
class ShoppingListTypePostgreSQLLoaderTest extends DatabaseTestCase
{
    private ShoppingListTypeLoader $loader;
    private ShoppingListTypeRepository $repository;
    private DataPersister $persister;
    private User $user;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = static::getContainer()->get(ShoppingListTypeLoader::class);
        $this->repository = static::getContainer()->get(ShoppingListTypeRepository::class);
        $this->persister = new DataPersister(static::getContainer());
        $this->user = new UserBuilder()->build();
        $this->other = new UserBuilder()->build();
        $this->persister->saveUser($this->user);
        $this->persister->saveUser($this->other);
        foreach ([new DepartmentBuilder()->isMeat(), new DepartmentBuilder()->isCheese(), new DepartmentBuilder()->isMiscellaneous()] as $department) {
            $this->persister->saveDepartment($department->build());
        }
    }

    public function testWithoutAnyTypeTheListIsEmptyAfterASingleQuery(): void
    {
        // Given
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $types = $this->loader->list($this->user->getId());

        // Then
        $this->assertSame([], $types);
        $this->assertSame(1, $counter->count());
    }

    public function testTheTypesAreSortedByCreationDateThenIdWithTheirDepartmentsAndProductCount(): void
    {
        // Given : créé en deuxième mais avec la date la plus ancienne, et deux types à la même date
        $this->savedType('b-type', $this->user, 'Marché', '2026-10-31 11:00:00', ['fromage']);
        $this->savedType('c-type', $this->user, 'Poissonnerie', '2026-10-31 11:00:00');
        $this->savedType('z-type', $this->user, 'Boucherie', '2026-10-31 10:00:00', ['viande', 'divers']);
        $butter = new IngredientBuilder()->isButter()->withCreator($this->user, true)->withListDestination('z-type')->build();
        $flour = new IngredientBuilder()->isFlour()->withCreator($this->user, true)->withListDestination('z-type')->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($this->user, true)->withListDestination('b-type')->build();
        $pasta = new IngredientBuilder()->isPasta()->withCreator($this->user, true)->withListDestination('main')->build();
        $rice = new IngredientBuilder()->isPasta()->withName('Riz')->withSlug('riz')->withCreator($this->user)->build();
        foreach ([$butter, $flour, $egg, $pasta, $rice] as $ingredient) {
            $this->persister->saveIngredient($ingredient);
        }

        // When
        $types = $this->loader->list($this->user->getId());

        // Then
        $this->assertSame(['z-type', 'b-type', 'c-type'], array_column(array_map(static fn($type) => (array) $type, $types), 'id'));
        $this->assertSame([['Boucherie', ['divers', 'viande'], 2], ['Marché', ['fromage'], 1], ['Poissonnerie', [], 0]], array_map(static fn($type): array => [$type->name, $type->departmentSlugs, $type->ingredientCount], $types));
    }

    public function testNeverTheTypesNorTheProductsOfAnotherUser(): void
    {
        // Given
        $this->savedType('mine', $this->user, 'Boucherie', '2026-10-31 10:00:00', ['viande']);
        $this->savedType('theirs', $this->other, 'Boucherie', '2026-10-31 10:00:00', ['viande']);
        $theirs = new IngredientBuilder()->isButter()->withCreator($this->other, true)->withListDestination('mine')->build();
        $this->persister->saveIngredient($theirs);

        // When
        $mine = $this->loader->list($this->user->getId());
        $detail = $this->loader->get('mine', $this->user->getId());

        // Then : le produit d'un autre qui désignerait mon type n'y est ni compté ni listé
        $this->assertSame([['mine', ['viande'], 0]], array_map(static fn($type): array => [$type->id, $type->departmentSlugs, $type->ingredientCount], $mine));
        $this->assertSame([], $detail->ingredients);
        $this->assertSame(['theirs'], array_map(static fn($type): string => $type->id, $this->loader->list($this->other->getId())));
    }

    public function testTheDetailNamesTheDepartmentsAndTheProductsWithTheirDepartment(): void
    {
        // Given
        $this->savedType('type-1', $this->user, 'Boucherie', '2026-10-31 10:00:00', ['viande', 'fromage']);
        $flour = new IngredientBuilder()->isFlour()->withCreator($this->user, true)->withListDestination('type-1')->build();
        $butter = new IngredientBuilder()->isButter()->withCreator($this->user, true)->withListDestination('type-1')->build();
        $this->persister->saveIngredient($flour);
        $this->persister->saveIngredient($butter);

        // When
        $detail = $this->loader->get('type-1', $this->user->getId());

        // Then
        $this->assertSame(['type-1', 'Boucherie'], [$detail->id, $detail->name]);
        $this->assertEquals([new ShoppingListTypeDepartmentDTO('fromage', 'fromage'), new ShoppingListTypeDepartmentDTO('viande', 'viande')], $detail->departments);
        $this->assertEquals(
            [
                new ShoppingListTypeIngredientDTO($butter->getSlug(), $butter->getName(), $butter->getDepartment()->getSlug(), $butter->getDepartment()->getName()),
                new ShoppingListTypeIngredientDTO($flour->getSlug(), $flour->getName(), $flour->getDepartment()->getSlug(), $flour->getDepartment()->getName()),
            ],
            $detail->ingredients,
        );
    }

    public function testTheTypeOfAnotherUserIsNotFoundLikeAnUnknownOne(): void
    {
        // Given
        $this->savedType('theirs', $this->other, 'Boucherie', '2026-10-31 10:00:00');

        // Then
        foreach (['theirs', 'inconnu'] as $id) {
            try {
                $this->loader->get($id, $this->user->getId());
                $this->fail("Le type $id aurait dû être introuvable.");
            } catch (ShoppingListTypeException $exception) {
                $this->assertEquals(ShoppingListTypeException::notFound(), $exception);
            }
        }
    }

    public function testTheNumberOfQueriesDoesNotGrowWithTheNumberOfTypesDepartmentsAndProducts(): void
    {
        // Given
        $this->savedType('one', $this->user, 'Boucherie', '2026-10-31 10:00:00', ['viande']);
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();
        $this->loader->list($this->user->getId());
        $this->loader->get('one', $this->user->getId());
        $small = $counter->count();

        $this->savedType('two', $this->user, 'Marché', '2026-10-31 11:00:00', ['fromage', 'divers']);
        $this->savedType('three', $this->user, 'Primeur', '2026-10-31 12:00:00');
        foreach ([new IngredientBuilder()->isButter(), new IngredientBuilder()->isFlour(), new IngredientBuilder()->isEgg()] as $builder) {
            $this->persister->saveIngredient($builder->withCreator($this->user, true)->withListDestination('two')->build());
        }
        $this->entityManager->clear();
        $counter->reset();

        // When
        $this->loader->list($this->user->getId());
        $this->loader->get('two', $this->user->getId());

        // Then : trois requêtes pour la liste (types, rayons, produits), trois pour le détail, avec un type comme avec trois
        $this->assertSame(6, $small);
        $this->assertSame(6, $counter->count());
    }

    /**
     * @param list<string> $departments
     */
    private function savedType(string $id, User $owner, string $name, string $createdAt, array $departments = []): void
    {
        $this->repository->save(ShoppingListType::create($id, $owner->getId(), $name, new DateTimeImmutable($createdAt))->replaceDepartments($departments));
    }
}
