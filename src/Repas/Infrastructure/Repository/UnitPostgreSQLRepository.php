<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Unit as UnitModel;
use Repas\Repas\Infrastructure\Entity\Unit as UnitEntity;

class UnitPostgreSQLRepository extends ServiceEntityRepository implements UnitRepository
{


    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, UnitEntity::class);
    }

    public function save(UnitModel $unit): void
    {
        $unitEntity = $this->find($unit->getSlug());
        if ($unitEntity) {
            $this->updateEntity($unitEntity, $unit);
        } else {
            $unitEntity = UnitEntity::fromModel($unit);
        }

        $this->getEntityManager()->persist($unitEntity);
        $this->getEntityManager()->flush();
    }


    /**
     * @throws UnitException
     */
    public function findBySlug(string $slug): UnitModel
    {
        return $this->find($slug)?->getModel()
            ?? throw UnitException::notFound();
    }

    public function delete(UnitModel $unit): void
    {
        $entityManager = $this->getEntityManager();

        $unitEntity = $this->find($unit->getSlug());
        if ($unitEntity) {
            $entityManager->remove($unitEntity);
            $entityManager->flush();
        }
    }

    /**
     * Met à jour une entité existante avec les données du modèle.
     */
    private function updateEntity(UnitEntity $entity, UnitModel $model): void
    {
        $entity->setName($model->getName())
            ->setSymbol($model->getSymbol());
    }
}
