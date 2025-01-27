<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Infrastructure\Entity\ShoppingList as ShoppingListEntity;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Entity\User as UserEntity;

class ShoppingListPostgreSQLRepository extends ServiceEntityRepository implements ShoppingListRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShoppingListEntity::class);
    }

    /**
     * @return ShoppingList[]
     */
    public function findByOwner(User $owner): array
    {
        $userEntity = UserEntity::fromModel($owner);
        $shoppingListEntities = $this->findBy(['owner' => $userEntity], ['createdAt' => 'DESC']);
        return array_map(fn(ShoppingListEntity $entity) => $entity->getModel(), $shoppingListEntities);
    }

    public function findById(string $id): ?ShoppingList
    {
        $shoppingListEntity = $this->find($id);
        return $shoppingListEntity?->getModel();
    }
}
