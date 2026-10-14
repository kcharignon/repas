<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Domain\Tool\Tab;

/**
 * @extends AbstractInMemoryRepository<Ingredient>
 */
class IngredientInMemoryRepository extends AbstractInMemoryRepository implements IngredientRepository
{
    use SaveModelTrait;

    protected static function getClassName(): string
    {
        return Ingredient::class;
    }

    public function findPurchaseUnitSlug(string $slug): string
    {
        return $this->findOneBySlug($slug)->getDefaultPurchaseUnit()->getSlug();
    }

    public function exists(string $slug): bool
    {
        return isset($this->models[$slug]);
    }

    public function findOneBySlug(string $slug): Ingredient
    {
        return $this->models->find(fn(Ingredient $ingredient) => $ingredient->getSlug() === $slug) ?? throw IngredientException::notFound($slug);
    }

    public function findByDepartmentAndOwner(Department $department, string $ownerId): Tab
    {
        return $this->models->filter(fn(Ingredient $ingredient) => $ingredient->getDepartment()->isEqual($department) && $ingredient->getCreatorId() === $ownerId);
    }

    public function findByOwner(string $ownerId): Tab
    {
        return $this->models->filter(fn(Ingredient $ingredient) => $ingredient->getCreatorId() === $ownerId);
    }

    /**
     * @return Tab<Ingredient>
     */
    public function findAll(): Tab
    {
        return $this->models;
    }

    public function delete(Ingredient $ingredient): void
    {
        unset($this->models[$ingredient->getId()]);
    }
}
