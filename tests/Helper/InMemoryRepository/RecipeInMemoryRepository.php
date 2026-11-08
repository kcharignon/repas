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

    /** Lectures de recettes EN LOT (`findByIds`) : de quoi prouver qu'une recette sans composant n'en fait aucune et qu'une recette composée n'en fait qu'une. */
    public int $batchReads = 0;

    /**
     * @throws RecipeException
     */
    public function findOneById(string $id): Recipe
    {
        return $this->models[$id] ?? throw RecipeException::notFound($id);
    }

    /**
     * Comme le vrai dépôt : un modèle SANS ses composants (les arêtes ne sont pas lues, `hasComponents()` dit pourtant s'il en a), dans l'ordre
     * demandé, un identifiant inconnu absent.
     */
    public function findByIds(array $ids): array
    {
        $this->batchReads++;
        $recipes = [];
        foreach (array_values(array_unique($ids)) as $id) {
            $recipe = $this->models[$id] ?? null;
            if ($recipe instanceof Recipe) {
                $recipes[$id] = Recipe::load([
                    'id' => $recipe->getId(),
                    'name' => $recipe->getName(),
                    'serving' => $recipe->getServing(),
                    'author_id' => $recipe->getAuthorId(),
                    'type' => $recipe->getType(),
                    'rows' => $recipe->getRows(),
                    'original_id' => $recipe->getOriginalId(),
                    'version' => $recipe->getVersion(),
                    'secret' => $recipe->isSecret(),
                    'favorite' => $recipe->isFavorite(),
                    'marmiton_id' => $recipe->getMarmitonId(),
                    'source_url' => $recipe->getSourceUrl(),
                    'components' => null,
                    'component_count' => $recipe->getComponentCount(),
                ]);
            }
        }

        return $recipes;
    }

    public function isUsedAsComponent(string $recipeId): bool
    {
        /** @var Recipe $recipe */
        foreach ($this->models as $recipe) {
            if (in_array($recipeId, $recipe->componentIds(), true)) {
                return true;
            }
        }

        return false;
    }

    public function isUsedByOrdinaryRecipe(string $recipeId): bool
    {
        /** @var Recipe $recipe */
        foreach ($this->models as $recipe) {
            if (!$recipe->isSecret() && in_array($recipeId, $recipe->componentIds(), true)) {
                return true;
            }
        }

        return false;
    }

    public function hasSecretComponent(string $recipeId): bool
    {
        $recipe = $this->models[$recipeId] ?? null;
        foreach ($recipe?->componentIds() ?? [] as $componentId) {
            if (($this->models[$componentId] ?? null)?->isSecret() === true) {
                return true;
            }
        }

        return false;
    }

    public function findByAuthor(string $authorId): Tab
    {
        return $this->models->filter(fn(Recipe $recipe) => $recipe->getAuthorId() === $authorId);
    }

    public function findBy(array $criteria, ?array $orderBy = null): Tab
    {
        throw new Exception("Not implemented");
    }

    /**
     * Toutes les recettes enregistrées, de tous les auteurs (pour qu'un test dise « rien n'a été écrit »).
     *
     * @return list<Recipe>
     */
    public function findAll(): array
    {
        return array_values($this->models->toArray());
    }

    public function findMarmitonNeutralId(string $marmitonId): ?string
    {
        /** @var Recipe $recipe */
        foreach ($this->models as $recipe) {
            if ($recipe->getMarmitonId() === $marmitonId) {
                return $recipe->getId();
            }
        }

        return null;
    }

    public function findCopyIdOf(string $originalId, string $authorId): ?string
    {
        /** @var Recipe $recipe */
        foreach ($this->models as $recipe) {
            if ($recipe->getOriginalId() === $originalId && $recipe->getAuthorId() === $authorId) {
                return $recipe->getId();
            }
        }

        return null;
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
