<?php

namespace Repas\Shared\Infrastructure\Service;


use Repas\Repas\Domain\Model\Recipe as RecipeModel;
use Repas\Repas\Infrastructure\Entity\Recipe as RecipeEntity;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Infrastructure\Interface\EntityModelMapper;

class RecipeMapper implements EntityModelMapper
{

    public function toModel(object $entity): ModelInterface
    {
        if (!$entity instanceof RecipeEntity) {
            throw new \InvalidArgumentException('Expected a RecipeEntity instance.');
        }

        return RecipeModel::create(
            $entity->getId(),
            $entity->getName(),
            $entity->getServing(),
            $entity->getAuthor()->toModel(),
            $entity->getType()->toModel(),
            array_map(fn($row) => $row->toModel(), $entity->getRows()->toArray())
        );
    }

    public function toEntity(ModelInterface $model): object
    {
        // TODO: Implement toEntity() method.
    }

}
