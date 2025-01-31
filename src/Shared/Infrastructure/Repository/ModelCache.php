<?php

namespace Repas\Shared\Infrastructure\Repository;

use Repas\Shared\Domain\Model\ModelInterface;

/**
 * @template T of ModelInterface
 */
class ModelCache
{
    private array $models = [];

    /**
     * Retrieve a model from the cache.
     *
     * @param class-string<T> $className The class name of the model.
     * @param string $identifier The identifier of the model.
     * @return T|null The model instance, or null if not found.
     */
    public function getModelCache(string $className, string $identifier): ?ModelInterface
    {
        return $this->models[$className][$identifier] ?? null;
    }

    /**
     * Add a model to the cache.
     *
     * @param ModelInterface $model The model to cache.
     */
    public function setModelCache(ModelInterface $model): void
    {
        $this->models[$model::class] ??= [];
        $this->models[$model::class][$model->getId()] = $model;
    }

    /**
     * Add a model to the cache.
     *
     * @param ModelInterface $model The model to cache.
     */
    public function removeModelCache(ModelInterface $model): void
    {
        unset($this->models[$model::class][$model->getId()]);
    }

    /**
     * @param string $className
     * @param string $identifier
     * @return bool
     */
    public function isCachedExists(string $className, string $identifier): bool
    {
        return isset($this->models[$className][$identifier]);
    }

    public function reset(): void
    {
        $this->models = [];
    }
}
