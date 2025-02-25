<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Tool\Tab;

/**
 * Class AbstractInMemoryRepository
 * @template T of ModelInterface
 */
abstract class AbstractInMemoryRepository
{
    /** @var Tab<T> */
    protected Tab $models;

    /**
     * @param T[] $models
     */
    public function __construct(array $models = [])
    {
        $this->models = new Tab([], static::getClassName());

        foreach ($models as $model) {
            assert($model instanceof ModelInterface);
            $this->models[$model->getId()] = clone $model;
        }
    }

    abstract protected static function getClassName(): string;
}
