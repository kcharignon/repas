<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Exception;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Model\User;

class RecipeInMemoryRepository extends AbstractInMemoryRepository implements RecipeRepository
{
    use SaveModelTrait;

    protected static function getClassName(): string
    {
        return Recipe::class;
    }

    /**
     * @throws RecipeException
     */
    public function findOneById(string $id): Recipe
    {
        return $this->models[$id] ?? throw RecipeException::notFound($id);
    }

    public function findByAuthor(User $author): Tab
    {
        return $this->models->filter(fn(Recipe $recipe) => $author->isEqual($recipe->getAuthor()));
    }

    public function findByAuthorAndType(User $author, RecipeType $type): Tab
    {
        return $this->models->filter(fn(Recipe $recipe) => $recipe->getAuthor()->isEqual($author) && $recipe->getType()->isEqual($type));
    }

    public function findBy(array $criteria, ?array $orderBy = null): Tab
    {
        throw new Exception("Not implemented");
    }

    public function findByIngredient(Ingredient $ingredient): Tab
    {
        return $this->models->filter(
            fn(Recipe $recipe) => $recipe->getRows()->find(
                fn(RecipeRow $row) => $row->getIngredient()->isEqual($ingredient)
            ) !== null
        );
    }

    public function delete(Recipe $recipe): void
    {
        unset($this->models[$recipe->getId()]);
    }
}
