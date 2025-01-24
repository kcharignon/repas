<?php

namespace Repas\Repas\Infrastructure\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Infrastructure\Entity\RecipeType;

/**
 * @extends ServiceEntityRepository<RecipeType>
 */
class RecipeTypePostgreSQLRepository extends ServiceEntityRepository implements RecipeTypeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecipeType::class);
    }

    public function getAll(): array
    {
        return $this->findBy([], ['sequence' => 'ASC']);
    }

}
