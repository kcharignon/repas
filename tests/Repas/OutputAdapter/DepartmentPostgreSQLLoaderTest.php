<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\DepartmentLoader;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Application\Query\ListDepartments\DepartmentListItemDTO;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Model\DepartmentIcon;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\User\Domain\Model\User;
use Repas\Tests\Helper\DatabaseTestCase;

class DepartmentPostgreSQLLoaderTest extends DatabaseTestCase
{
    private const string USER_ID = 'user-without-type';

    private DepartmentRepository $departmentRepository;
    private DepartmentLoader $departmentLoader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->departmentRepository = static::getContainer()->get(DepartmentRepository::class);
        $this->departmentLoader = static::getContainer()->get(DepartmentLoader::class);
    }

    public function testListReturnsNothingWhenThereIsNone(): void
    {
        // When
        $result = $this->departmentLoader->list(self::USER_ID);

        // Then
        $this->assertSame([], $result);
    }

    public function testListReturnsDTOsOfCreatedDepartments(): void
    {
        // Given
        $this->departmentRepository->save(
            new DepartmentBuilder()->withSlug('zzz-outils')->withName('Zzz Outils')->withImage('file://images/zzz.jpg')->build()
        );

        // When
        $result = $this->departmentLoader->list(self::USER_ID);

        // Then
        $this->assertContainsOnlyInstancesOf(DepartmentListItemDTO::class, $result);
        $this->assertEquals(
            new DepartmentListItemDTO('zzz-outils', 'Zzz Outils', 'file://images/zzz.jpg', null, null, 0),
            $this->findBySlug($result, 'zzz-outils'),
        );
    }

    public function testTheIconIsReadWhenThereIsOneAndTheKeyIsAlwaysThere(): void
    {
        // Given : un rayon avec une icône, un sans
        $this->departmentRepository->save(new DepartmentBuilder()->withSlug('aaa-surgele')->withName('Surgelé')->withImage('file://images/s.jpg')->withIcon(DepartmentIcon::SNOWFLAKE)->build());
        $this->departmentRepository->save(new DepartmentBuilder()->withSlug('bbb-divers')->withName('Divers')->withImage('file://images/d.jpg')->build());

        // When
        $list = $this->departmentLoader->list(self::USER_ID);

        // Then
        $this->assertEquals(new DepartmentListItemDTO('aaa-surgele', 'Surgelé', 'file://images/s.jpg', 'snowflake', null, 0), $this->findBySlug($list, 'aaa-surgele'));
        $this->assertEquals(new DepartmentListItemDTO('bbb-divers', 'Divers', 'file://images/d.jpg', null, null, 0), $this->findBySlug($list, 'bbb-divers'));
        $this->assertSame('snowflake', $this->departmentLoader->get('aaa-surgele')->icon);
        $this->assertNull($this->departmentLoader->get('bbb-divers')->icon);
    }

    public function testAnIconThatThisVersionDoesNotKnowIsReadAsNoIconWithoutFailing(): void
    {
        // Given : une valeur écrite par une version plus récente
        $this->departmentRepository->save(new DepartmentBuilder()->withSlug('zzz-outils')->withName('Zzz Outils')->withImage('file://images/zzz.jpg')->build());
        $this->entityManager->getConnection()->executeStatement("UPDATE department SET icon = 'bag' WHERE slug = 'zzz-outils'");
        $this->entityManager->clear();

        // When / Then
        $this->assertNull($this->departmentLoader->get('zzz-outils')->icon);
        $this->assertNull($this->findBySlug($this->departmentLoader->list(self::USER_ID), 'zzz-outils')->icon);
    }

    public function testGetReturnsTheDepartment(): void
    {
        // Given
        $this->departmentRepository->save(new DepartmentBuilder()->withSlug('zzz-outils')->withName('Zzz Outils')->withImage('file://images/zzz.jpg')->build());

        // When
        $result = $this->departmentLoader->get('zzz-outils');

        // Then
        $this->assertEquals(new DepartmentDTO('zzz-outils', 'Zzz Outils', 'file://images/zzz.jpg', null), $result);
    }

    public function testGetFailsForAnUnknownDepartment(): void
    {
        // Then
        $this->expectExceptionObject(DepartmentException::notFound());

        // When
        $this->departmentLoader->get('inconnu');
    }

    public function testListTellsTheListTypeOfEachDepartmentForTheReaderOnly(): void
    {
        // Given : « Boucherie » (de moi) tient le rayon « viande » ; un type d'un AUTRE compte tient « fromage » ; « divers » n'a pas de type
        $persister = new DataPersister(static::getContainer());
        foreach (['viande', 'fromage', 'divers'] as $slug) {
            $this->departmentRepository->save(new DepartmentBuilder()->withSlug($slug)->withName(ucfirst($slug))->build());
        }
        $mine = ShoppingListType::create('type-mine', self::USER_ID, 'Boucherie', new \DateTimeImmutable())->replaceDepartments(['viande']);
        $theirs = ShoppingListType::create('type-theirs', 'someone-else', 'Fromagerie', new \DateTimeImmutable())->replaceDepartments(['fromage']);
        $persister->saveShoppingListType($mine);
        $persister->saveShoppingListType($theirs);

        // When
        $mineRead = $this->departmentLoader->list(self::USER_ID);
        $theirsRead = $this->departmentLoader->list('someone-else');
        $nobodyRead = $this->departmentLoader->list('nobody');

        // Then : le rayon est global, son type est propre au compte qui lit
        $this->assertSame(['type-mine', null, null], array_map(fn(string $slug): ?string => $this->findBySlug($mineRead, $slug)->listTypeId, ['viande', 'fromage', 'divers']));
        $this->assertSame([null, 'type-theirs', null], array_map(fn(string $slug): ?string => $this->findBySlug($theirsRead, $slug)->listTypeId, ['viande', 'fromage', 'divers']));
        $this->assertSame([null, null, null], array_map(fn(string $slug): ?string => $this->findBySlug($nobodyRead, $slug)->listTypeId, ['viande', 'fromage', 'divers']));
    }

    public function testAnAttachmentToATypeThatNoLongerExistsIsReadAsNoType(): void
    {
        // Given : un rattachement resté en base alors que son type a disparu (donnée périmée)
        $this->departmentRepository->save(new DepartmentBuilder()->withSlug('viande')->withName('Viande')->build());
        $this->entityManager->getConnection()->executeStatement("INSERT INTO department_list_type (owner_id, department_slug, list_type_id) VALUES (?, 'viande', 'gone-type')", [self::USER_ID]);

        // When / Then
        $this->assertNull($this->findBySlug($this->departmentLoader->list(self::USER_ID), 'viande')->listTypeId);
    }

    public function testListReadsTheTypesInConstantQueries(): void
    {
        // Given : autant de rayons que l'on veut, tous rattachés
        $persister = new DataPersister(static::getContainer());
        $slugs = [];
        foreach (range(1, 8) as $i) {
            $slugs[] = 'rayon-' . $i;
            $this->departmentRepository->save(new DepartmentBuilder()->withSlug('rayon-' . $i)->withName('Rayon ' . $i)->build());
        }
        $persister->saveShoppingListType(ShoppingListType::create('type-1', self::USER_ID, 'Marché', new \DateTimeImmutable())->replaceDepartments($slugs));
        $this->entityManager->clear();

        // When
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();
        $this->departmentLoader->list(self::USER_ID);

        // Then
        $this->assertSame(1, $counter->count());
    }

    public function testListCountsTheIngredientsOfTheReaderInEachDepartmentOnly(): void
    {
        // Given : deux comptes (dont un administrateur) ; « céréale » : 2 à moi, 1 à un autre ; « viande » : 1 à un autre ; « boulangerie » : aucun
        $me = $this->savedUser();
        $admin = $this->savedUser(admin: true);
        $other = $this->savedUser();
        $this->savedIngredient(new IngredientBuilder()->withName('aaa blé')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($me, true));
        $this->savedIngredient(new IngredientBuilder()->withName('bbb riz')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($me, true));
        $this->savedIngredient(new IngredientBuilder()->withName('ccc orge')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($other, true));
        $this->savedIngredient(new IngredientBuilder()->withName('ddd boeuf')->withDepartment(new DepartmentBuilder()->isMeat())->withCreator($other, true));
        $this->savedIngredient(new IngredientBuilder()->withName('eee blé admin')->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($admin, true));
        $this->departmentRepository->save(new DepartmentBuilder()->isBakery()->build());

        // When
        $mine = $this->departmentLoader->list($me->getId());
        $theirs = $this->departmentLoader->list($other->getId());
        $admins = $this->departmentLoader->list($admin->getId());
        $nobody = $this->departmentLoader->list('nobody');

        // Then : tous les rayons sont rendus, le compte est celui du lecteur seul (un administrateur ne compte pas ceux des autres)
        $count = fn(array $list): array => array_map(fn(string $slug): int => $this->findBySlug($list, $slug)->ingredientCount, ['cereale', 'viande', 'boulangerie']);
        $this->assertCount(3, $mine);
        $this->assertSame([2, 0, 0], $count($mine));
        $this->assertSame([1, 1, 0], $count($theirs));
        $this->assertSame([1, 0, 0], $count($admins));
        $this->assertSame([0, 0, 0], $count($nobody));
    }

    public function testListCountsThePartsLikeTheScreenOfADepartmentDoes(): void
    {
        // Given : un ingrédient et sa partie (ADR 0038), dans le même rayon : l'écran du rayon liste les deux
        $me = $this->savedUser();
        $department = new DepartmentBuilder()->isCereal();
        $this->savedIngredient(new IngredientBuilder()->withName('Oeuf')->withDepartment($department)->withCreator($me, true));
        $part = $this->savedIngredient(new IngredientBuilder()->withName('Jaune oeuf')->withDepartment($department)->withCreator($me, true)->withParent('oeuf', 0.5));

        // When
        $count = $this->findBySlug($this->departmentLoader->list($me->getId()), 'cereale')->ingredientCount;
        $screen = static::getContainer()->get(\Repas\Repas\Application\Loader\IngredientLoader::class)->listByDepartment('cereale', $me->getId());

        // Then : le compte est exactement ce que l'écran du rayon montre
        $this->assertNotNull($part->getParentSlug());
        $this->assertSame(2, $count);
        $this->assertCount($count, $screen->ingredients);
    }

    public function testListCountsIngredientsInConstantQueries(): void
    {
        // Given : 3 rayons, quelques ingrédients
        $me = $this->savedUser();
        $this->seedDepartmentsWithIngredients($me, 3, 2);
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();
        $this->departmentLoader->list($me->getId());
        $small = $counter->count();

        // When : 30 rayons de plus, bien plus d'ingrédients
        $this->seedDepartmentsWithIngredients($me, 30, 3, 'grand');
        $this->entityManager->clear();
        $counter->reset();
        $result = $this->departmentLoader->list($me->getId());

        // Then : une requête, quel que soit le nombre de rayons et d'ingrédients
        $this->assertSame(1, $small);
        $this->assertSame(1, $counter->count());
        $this->assertSame(3 * 2 + 30 * 3, array_sum(array_map(static fn(DepartmentListItemDTO $d): int => $d->ingredientCount, $result)));
    }

    private function seedDepartmentsWithIngredients(User $owner, int $departments, int $perDepartment, string $prefix = 'petit'): void
    {
        for ($d = 1; $d <= $departments; $d++) {
            $slug = sprintf('%s-rayon-%d', $prefix, $d);
            $department = new DepartmentBuilder()->withSlug($slug)->withName('Rayon ' . $slug);
            for ($i = 1; $i <= $perDepartment; $i++) {
                $this->savedIngredient(new IngredientBuilder()->withName(sprintf('Ingrédient %s %d', $slug, $i))->withDepartment($department)->withCreator($owner, true));
            }
        }
    }

    private function savedUser(bool $admin = false): User
    {
        $user = $admin ? new UserBuilder()->isAdmin()->build() : new UserBuilder()->build();
        new DataPersister(static::getContainer())->saveUser($user);

        return $user;
    }

    private function savedIngredient(IngredientBuilder $builder): Ingredient
    {
        $ingredient = $builder->build();
        new DataPersister(static::getContainer())->saveIngredient($ingredient);

        return $ingredient;
    }

    public function testListIsOrderedBySlug(): void
    {
        // Given
        $this->departmentRepository->save(new DepartmentBuilder()->withSlug('zzz-outils')->withName('Aaa Outils')->build());
        $this->departmentRepository->save(new DepartmentBuilder()->withSlug('aaa-outils')->withName('Zzz Outils')->build());

        // When
        $result = $this->departmentLoader->list(self::USER_ID);

        // Then
        $slugs = array_map(static fn(DepartmentListItemDTO $department): string => $department->slug, $result);
        $this->assertLessThan(array_search('zzz-outils', $slugs), array_search('aaa-outils', $slugs));
    }

    /**
     * @param list<DepartmentListItemDTO> $departments
     */
    private function findBySlug(array $departments, string $slug): ?DepartmentListItemDTO
    {
        foreach ($departments as $department) {
            if ($department->slug === $slug) {
                return $department;
            }
        }

        return null;
    }
}
