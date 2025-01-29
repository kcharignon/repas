<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;

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
}
