<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Infrastructure\Entity\Unit as UnitEntity;

class RecipePostgreSQLRepository  extends ServiceEntityRepository implements RecipeRepository
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry
    ) {
        parent::__construct($this->managerRegistry, UnitEntity::class);
    }

    public function save(Recipe $recipe): void
    {
        if ($this->find($recipe->getId())) {
            $this->update($recipe);
        } else {
            $this->insert($recipe);
        }
    }
}
