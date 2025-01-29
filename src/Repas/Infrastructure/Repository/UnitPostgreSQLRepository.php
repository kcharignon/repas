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
use Repas\Shared\Infrastructure\Repository\ModelCache;

readonly class UnitPostgreSQLRepository extends PostgreSQLRepository implements UnitRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private ModelCache $modelCache,
    ) {
        parent::__construct($managerRegistry, UnitEntity::class);
    }

    public function save(UnitModel $unit): void
    {
        // On supprime le model du cache
        $this->modelCache->removeModelCache($unit);

        // On recupere l'entity
        $unitEntity = $this->entityRepository->find($unit->getSlug());
        if ($unitEntity) {
            $this->updateEntity($unitEntity, $unit);
        } else {
            $unitEntity = UnitEntity::fromModel($unit);
        }

        // On persiste les changement
        $this->entityManager->persist($unitEntity);
        $this->entityManager->flush();

        // On met le model en cache
        $this->modelCache->setModelCache($unit);
    }


    /**
     * @throws UnitException
     */
    public function getOneBySlug(string $slug): UnitModel
    {
        // On cherche dans le cache
        if (($model = $this->modelCache->getModelCache(UnitModel::class, $slug)) !== null) {
            return $model;
        }

        // On cherche en BDD
        if (($entity = $this->entityRepository->find($slug)) !== null) {
            $model = $this->convertEntityToModel($entity);
            // On stock model en cache
            $this->modelCache->setModelCache($model);
            return $model;
        }

        throw UnitException::notFound();
    }

    public function delete(UnitModel $unit): void
    {
        $this->modelCache->removeModelCache($unit);

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
}
