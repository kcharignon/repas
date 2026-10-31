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
use Repas\Tests\Helper\Builder\DepartmentBuilder;
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
            new DepartmentListItemDTO('zzz-outils', 'Zzz Outils', 'file://images/zzz.jpg', null, null),
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
        $this->assertEquals(new DepartmentListItemDTO('aaa-surgele', 'Surgelé', 'file://images/s.jpg', 'snowflake', null), $this->findBySlug($list, 'aaa-surgele'));
        $this->assertEquals(new DepartmentListItemDTO('bbb-divers', 'Divers', 'file://images/d.jpg', null, null), $this->findBySlug($list, 'bbb-divers'));
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
