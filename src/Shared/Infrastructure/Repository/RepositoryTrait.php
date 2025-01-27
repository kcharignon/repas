<?php

namespace Repas\Shared\Infrastructure\Repository;


use http\Exception\InvalidArgumentException;
use Repas\Repas\Domain\Model\ShoppingList as ShoppingListModel;
use Repas\Repas\Domain\Model\Unit as UnitModel;
use Repas\Repas\Infrastructure\Entity\ShoppingList as ShoppingListEntity;
use Repas\Repas\Infrastructure\Entity\Unit as UnitEntity;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\User\Domain\Model\User as UserModel;
use Repas\User\Infrastructure\Entity\User as UserEntity;

trait RepositoryTrait
{
    private function convertModelCriteriaToEntityCriteria(array $criteria): array
    {
        $result = [];
        foreach ($criteria as $key => $value) {
            if ($value instanceof ModelInterface) {
                $result[$key] = match (true) {
                    $value instanceof ShoppingListModel => ShoppingListEntity::fromModel($value),
                    $value instanceof UserModel => UserEntity::fromModel($value),
                    $value instanceof UnitModel => UnitEntity::fromModel($value),
                    default => throw new InvalidArgumentException(sprintf('The model %s is not supported in criteria', $value::class)),
                };
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
