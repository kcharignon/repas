<?php

namespace Repas\Repas\Infrastructure\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\RecipeType as RecipeTypeModel;
use Repas\Repas\Infrastructure\Entity\RecipeType as RecipeTypeEntity;
use Repas\Shared\Domain\Tool\Tab;

/**
 * @extends ServiceEntityRepository<RecipeTypeEntity>
 */
class RecipeTypePostgreSQLRepository extends ServiceEntityRepository implements RecipeTypeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecipeTypeEntity::class);
    }

    /**
     * @return Tab<RecipeTypeModel>
     */
    public function getAll(): Tab
    {
        return Tab::fromArray($this->findBy([], ['sequence' => 'ASC']))
            ->map(fn(RecipeTypeEntity $entity) => $entity->getModel());
    }

}
