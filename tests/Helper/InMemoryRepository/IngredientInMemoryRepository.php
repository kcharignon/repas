<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Model\User;

class IngredientInMemoryRepository extends AbstractInMemoryRepository implements IngredientRepository
{
    use SaveModelTrait;

    protected static function getClassName(): string
    {
        return Ingredient::class;
    }

    public function findOneBySlug(string $slug): Ingredient
    {
        return $this->models->find(fn(Ingredient $ingredient) => $ingredient->getSlug() === $slug) ?? throw IngredientException::notFound($slug);
    }

    public function findByDepartmentAndOwner(Department $department, User $owner): Tab
    {
        return $this->models->filter(fn(Ingredient $ingredient) => $ingredient->getDepartment()->isEqual($department) && $ingredient->getCreator()->isEqual($owner));
    }

    public function findByOwner(User $owner): Tab
    {
        return $this->models->filter(fn(Ingredient $ingredient) => $ingredient->getCreator()->isEqual($owner));
    }

    public function cachedByRecipe(string $recipeId): void
    {
        // TODO: Implement cachedByRecipe() method.
    }

    /**
     * @return Tab<Ingredient>
     */
    public function findAll(): Tab
    {
        return $this->models;
    }


}
