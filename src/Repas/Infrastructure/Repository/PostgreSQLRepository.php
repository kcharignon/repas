<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Repas\Shared\Domain\Model\ModelInterface;

abstract readonly class PostgreSQLRepository
{
    protected EntityManagerInterface $entityManager;
    protected ObjectRepository $entityRepository;

    public function __construct(ManagerRegistry $managerRegistry,string $className) {
        $entityManager = $managerRegistry->getManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Expected EntityManagerInterface, got ' . get_class($entityManager));
        }
        $this->entityManager = $entityManager;
        $this->entityRepository = $entityManager->getRepository($className);
    }

    // TODO: refacto saves Methode()
//    protected function saveEntity(ModelInterface $model): void
//    {
//        $entity = $this->entityRepository->find($model->getId());
//
//        if ($entity !== null) {
//            // The entity already exists: update it
//            $entity->updateFromModel($model);
//        } else {
//            // Create a new entity from the model using the static factory method.
//            $entity = $this->getEntityClass()::fromModel($model);
//            $this->entityManager->persist($entity);
//        }
//
//        $this->entityManager->flush();
//    }
//
//    abstract protected function getEntityClass(): string;
}
