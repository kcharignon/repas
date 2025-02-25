<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Shared\Domain\Model\ModelInterface;

/**
 * Can be use only with AbstractInMemoryRepository children
 */
trait SaveModelTrait
{
    public function save(ModelInterface $model): void
    {
        $this->models[$model->getId()] = clone $model;
    }
}
