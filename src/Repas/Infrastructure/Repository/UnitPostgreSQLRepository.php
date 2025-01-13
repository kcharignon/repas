<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Unit as UnitModel;
use Repas\Repas\Infrastructure\Entity\Unit as UnitEntity;

class UnitPostgreSQLRepository extends ServiceEntityRepository implements UnitRepository
{


    public function __construct(
        private readonly ManagerRegistry $managerRegistry
    ) {
        parent::__construct($this->managerRegistry, UnitEntity::class);
    }

    public function save(UnitModel $unit): void
    {
        if ($this->find($unit->getId())) {
            $this->update($unit);
        } else {
            $this->insert($unit);
        }
    }



    private function insert(UnitModel $unit): void
    {
        //todo: Do something
    }

    private function update(UnitModel $unit): void
    {
        //todo: Do something
    }
}
