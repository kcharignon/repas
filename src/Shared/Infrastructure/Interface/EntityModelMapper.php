<?php

namespace Repas\Shared\Infrastructure\Interface;


use Repas\Shared\Domain\Model\ModelInterface;

interface EntityModelMapper
{
    public function toModel(object $entity): ModelInterface;

    public function toEntity(ModelInterface $model): object;
}
