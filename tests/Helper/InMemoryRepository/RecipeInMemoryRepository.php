<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Exception;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Shared\Domain\Tool\Tab;

/**
 * @extends AbstractInMemoryRepository<Recipe>
 */
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

    public function findByAuthor(string $authorId): Tab
    {
        return $this->models->filter(fn(Recipe $recipe) => $recipe->getAuthorId() === $authorId);
    }

    public function findBy(array $criteria, ?array $orderBy = null): Tab
    {
        throw new Exception("Not implemented");
    }

    public function authorHasAtLeast(string $authorId, int $count): bool
    {
        return $this->findByAuthor($authorId)->count() >= $count;
    }

    public function isIngredientUsed(string $ingredientSlug): bool
    {
        /** @var Recipe $recipe */
        foreach ($this->models as $recipe) {
            foreach ($recipe->getRows() as $row) {
                if ($row->getIngredient()->getSlug() === $ingredientSlug) {
                    return true;
                }
            }
        }

        return false;
    }

    public function delete(Recipe $recipe): void
    {
        unset($this->models[$recipe->getId()]);
    }

    public function findIdsByAuthor(string $authorId): array
    {
        return array_values($this->findByAuthor($authorId)->map(fn(Recipe $recipe) => $recipe->getId())->toArray());
    }

    public function makeCopiesOriginal(array $recipeIds): void
    {
        /** @var Recipe $recipe */
        foreach ($this->models as $recipe) {
            if (in_array($recipe->getOriginalId(), $recipeIds, true)) {
                $recipe->setOriginalId(null);
            }
        }
    }

    public function deleteByAuthor(string $authorId): void
    {
        foreach ($this->findIdsByAuthor($authorId) as $recipeId) {
            unset($this->models[$recipeId]);
        }
    }

    public function moveToType(string $fromTypeSlug, string $toTypeSlug): void
    {
        $this->movedToType[] = [$fromTypeSlug, $toTypeSlug];
    }

    /**
     * Les déplacements demandés, dans l'ordre : (type d'origine, type de destination). Double idiot : les recettes ne changent pas de
     * type ici, le déplacement réel se prouve dans le test du repository.
     *
     * @var list<array{string, string}>
     */
    public array $movedToType = [];
}
