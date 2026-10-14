<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Unit as UnitModel;
use Repas\Repas\Infrastructure\Entity\Unit as UnitEntity;
use Repas\Shared\Domain\Tool\Tab;

readonly class UnitPostgreSQLRepository extends PostgreSQLRepository implements UnitRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, UnitEntity::class);
    }

    public function findAll(): Tab
    {
        $units = new Tab($this->entityRepository->findBy([], ['slug' => 'ASC']), UnitEntity::class);
        return $units->map(fn(UnitEntity $entity) => $this->convertEntityToModel($entity), UnitModel::class);
    }


    public function exists(string $slug): bool
    {
        return $this->entityRepository->find($slug) !== null;
    }

    public function save(UnitModel $unit): void
    {
        // On recupere l'entity
        $unitEntity = $this->entityRepository->find($unit->getSlug());
        if ($unitEntity instanceof UnitEntity) {
            $this->updateEntity($unitEntity, $unit);
        } else {
            $unitEntity = UnitEntity::fromModel($unit);
        }

        // On persiste les changement
        $this->entityManager->persist($unitEntity);
        $this->entityManager->flush();
    }


    /**
     * @throws UnitException
     */
    public function findOneBySlug(string $slug): UnitModel
    {
        if (($entity = $this->entityRepository->find($slug)) !== null) {
            return $this->convertEntityToModel($entity);
        }

        throw UnitException::notFound($slug);
    }

    public function delete(UnitModel $unit): void
    {
        $this->entityRepository->createQueryBuilder('u')
            ->delete()
            ->where('u.slug = :slug')
            ->setParameter('slug', $unit->getSlug())
            ->getQuery()
            ->execute()
        ;
        $this->entityManager->clear();
    }

    /**
     * Met à jour une entité existante avec les données du modèle.
     */
    private function updateEntity(UnitEntity $entity, UnitModel $model): void
    {
        $entity->setName($model->getName())
            ->setSymbol($model->getSymbol());
    }

    private function convertEntityToModel(UnitEntity $entity): UnitModel
    {
        return UnitModel::load([
            'slug' => $entity->getSlug(),
            'name' => $entity->getName(),
            'symbol' => $entity->getSymbol(),
        ]);
    }

    /**
     * @param Tab<string> $slugs
     * @return Tab<UnitModel>
     */
    public function findBySlugs(Tab $slugs): Tab
    {
        // Les slugs absents de l'identity map sont lus en une requête, puis résolus un par un
        $this->preload($slugs);

        $models = Tab::newEmptyTyped(UnitModel::class);
        foreach ($slugs as $slug) {
            $models[] = $this->findOneBySlug($slug);
        }

        return $models;
    }
}
